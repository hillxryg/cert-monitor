/**
 * Manifest V3 service worker. It owns every network call and all persistent state.
 *
 * It may be terminated at any time, so there is deliberately NO module-level mutable
 * state in this file: auth token, enrollments, statuses and the offline heartbeat queue
 * are all in chrome.storage.local, and retries are driven by chrome.alarms.
 */
import { firstErrorMessage, tryRequest } from '../shared/api';
import { DEFAULT_HEARTBEAT_INTERVAL_SECONDS, DEFAULT_IDLE_TIMEOUT_SECONDS, FLUSH_ALARM } from '../shared/config';
import { buildLessonIdentity } from '../shared/lesson';
import { clamp, round2 } from '../shared/num';
import { getItem, setItem, updateItem } from '../shared/storage';
import { toEnrolledCampaign } from '../shared/enrollments';
import { findMatchingCampaign } from '../shared/url-match';
import type {
  ActionResult,
  AuthState,
  EnrolledCampaign,
  HeartbeatAck,
  HeartbeatPayload,
  JoinResponse,
  PopupMessage,
  LoginResponse,
  PageContext,
  PopupState,
  RuntimeMessage,
  Telemetry,
  TabMessage,
} from '../shared/types';
import { enqueueHeartbeat, flushQueue } from './queue';
import { syncEnrollments, type SyncResult } from './sync';

// ------------------------------------------------------------- lifecycle
async function ensureFlushAlarm(): Promise<void> {
  // Alarms survive worker restarts; only create it if it is missing.
  if (!(await chrome.alarms.get(FLUSH_ALARM))) {
    await chrome.alarms.create(FLUSH_ALARM, { delayInMinutes: 1, periodInMinutes: 1 });
  }
}

void ensureFlushAlarm(); // runs every time the worker wakes up
chrome.runtime.onInstalled.addListener(() => void ensureFlushAlarm());
chrome.runtime.onStartup.addListener(() => void flushQueue());

chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === FLUSH_ALARM) void flushQueue();
});

chrome.runtime.onMessage.addListener((message: unknown, sender, sendResponse) => {
  if (!isRuntimeMessage(message)) return false;

  void handleMessage(message, sender).then(sendResponse);
  return true; // keep the channel open for the async response
});

// -------------------------------------------------------------- routing
const POPUP_TYPES = new Set(['LOGIN', 'LOGOUT', 'JOIN', 'GET_STATE', 'SYNC']);
const CONTENT_TYPES = new Set(['GET_PAGE_CONTEXT', 'HEARTBEAT']);

function isRuntimeMessage(value: unknown): value is RuntimeMessage {
  if (typeof value !== 'object' || value === null) return false;
  const type = (value as { type?: unknown }).type;
  return typeof type === 'string' && (POPUP_TYPES.has(type) || CONTENT_TYPES.has(type));
}

async function handleMessage(message: RuntimeMessage, sender: chrome.runtime.MessageSender): Promise<unknown> {
  const fromContentScript = sender.tab !== undefined;

  try {
    switch (message.type) {
      case 'GET_PAGE_CONTEXT':
        return fromContentScript ? await getPageContext(sender) : inactiveContext();
      case 'HEARTBEAT':
        return fromContentScript ? await handleHeartbeat(message.telemetry, sender) : dropped('not_from_tab');
      case 'LOGIN':
      case 'LOGOUT':
      case 'JOIN':
      case 'GET_STATE':
      case 'SYNC':
        // Account actions are only accepted from the extension's own pages (the popup).
        if (fromContentScript || sender.id !== chrome.runtime.id) return fail('Not allowed.');
        return await handleAccountAction(message);
    }
  } catch (error) {
    console.error('[cert-monitor] message failed', error);
    return message.type === 'HEARTBEAT' ? dropped('error') : fail('Something went wrong. Please try again.');
  }
}

const dropped = (reason: string): HeartbeatAck => ({ delivered: 'dropped', reason });
const fail = (error: string): ActionResult => ({ ok: false, error });

// ------------------------------------------------------ page / heartbeat
async function campaignsFor(auth: AuthState): Promise<EnrolledCampaign[]> {
  const enrollments = await getItem('enrollments');
  return enrollments[String(auth.user.id)] ?? [];
}

const inactiveContext = (): PageContext => ({
  active: false,
  interval_seconds: DEFAULT_HEARTBEAT_INTERVAL_SECONDS,
  idle_timeout_seconds: DEFAULT_IDLE_TIMEOUT_SECONDS,
});

async function getPageContext(sender: chrome.runtime.MessageSender): Promise<PageContext> {
  const inactive = inactiveContext();

  // Matched against the TAB's URL, so frames (embedded players) follow the lesson page.
  const tabUrl = sender.tab?.url;
  const auth = await getItem('auth');
  if (!tabUrl || !auth) return inactive;

  const campaign = findMatchingCampaign(await campaignsFor(auth), tabUrl);
  if (!campaign) return inactive;

  return {
    active: true,
    interval_seconds: campaign.rules.heartbeat_interval_seconds || DEFAULT_HEARTBEAT_INTERVAL_SECONDS,
    idle_timeout_seconds: campaign.rules.idle_timeout_seconds || DEFAULT_IDLE_TIMEOUT_SECONDS,
  };
}

async function handleHeartbeat(telemetry: Telemetry, sender: chrome.runtime.MessageSender): Promise<HeartbeatAck> {
  const tab = sender.tab;
  if (!tab || tab.id === undefined || !tab.url) return dropped('no_tab');

  const auth = await getItem('auth');
  if (!auth) return dropped('logged_out');

  const campaign = findMatchingCampaign(await campaignsFor(auth), tab.url);
  if (!campaign) return dropped('no_campaign');

  const identity = buildLessonIdentity({
    tabUrl: tab.url,
    frameId: sender.frameId ?? 0,
    frameUrl: sender.url,
    kind: telemetry.kind,
    mediaId: telemetry.kind === 'media' ? telemetry.media_id : null,
  });
  if (!identity) return dropped('bad_url');

  const title = (telemetry.page_title || tab.title || '').trim().slice(0, 255);

  const common = {
    campaign_id: campaign.campaign_id,
    client_timestamp: Math.trunc(telemetry.client_timestamp),
    lesson_key: identity.key,
    lesson_group: identity.group,
    lesson_title: title === '' ? null : title,
    lesson_url: identity.url,
    tab_focused: Boolean(telemetry.is_focused),
    tab_visible: Boolean(telemetry.is_visible),
  };

  // Map the content script's telemetry onto the Laravel HeartbeatRequest field names.
  // Both <video> and <audio> (e.g. narrated SCORM slides) are timed media, which the API calls "video".
  const payload: HeartbeatPayload =
    telemetry.kind === 'media'
      ? {
          ...common,
          content_type: 'video',
          segment_start: round2(telemetry.segment_start),
          segment_end: round2(telemetry.segment_end),
          current_time: round2(telemetry.segment_end),
          duration: round2(telemetry.total_duration),
          playback_rate: clamp(telemetry.playback_rate, 0.0625, 16),
          muted: Boolean(telemetry.is_muted),
          is_playing: true, // a media segment is only ever reported for time it was playing
        }
      : {
          ...common,
          content_type: 'reading',
          scroll_percent: Math.round(clamp(telemetry.scroll_percent, 0, 100)),
          idle: Boolean(telemetry.is_idle),
        };

  await enqueueHeartbeat({
    id: crypto.randomUUID(),
    user_id: auth.user.id,
    tab_id: tab.id,
    enqueued_at: Date.now(),
    attempts: 0,
    payload,
  });
  await flushQueue(); // sends immediately when online, otherwise leaves it queued for the alarm

  const stillQueued = (await getItem('heartbeatQueue')).length > 0;
  return { delivered: stillQueued ? 'queued' : 'sent' };
}

// ------------------------------------------------------- account actions
async function handleAccountAction(message: PopupMessage): Promise<ActionResult> {
  switch (message.type) {
    case 'LOGIN':
      return login(message.email, message.password);
    case 'LOGOUT':
      return logout();
    case 'JOIN':
      return join(message.joinCode);
    case 'GET_STATE':
      return { ok: true, state: await buildState() };
    case 'SYNC': {
      const result = await syncAndNotify();
      return { ok: result.status === 'synced', sync_status: result.status, state: await buildState() };
    }
  }
}

/** Refresh enrollments from the server; if anything relevant changed, make open tabs re-check. */
async function syncAndNotify(): Promise<SyncResult> {
  const result = await syncEnrollments();
  if (result.changed) await broadcast({ type: 'CONTEXT_CHANGED' });
  return result;
}

async function login(email: string, password: string): Promise<ActionResult> {
  if (!email.trim() || !password) return fail('Enter your email and password.');

  const response = await tryRequest<LoginResponse>('POST', '/auth/login', {
    body: { email: email.trim(), password, device_name: 'chrome-extension' },
  });

  if (!response) return fail('Cannot reach the server. Check your connection and try again.');
  if (response.status === 429) return fail('Too many attempts. Wait a minute and try again.');
  if (!response.ok || !response.data) return fail(firstErrorMessage(response.data, 'Login failed.'));

  // A different account must not inherit the previous student's queue or numbers.
  const previous = await getItem('auth');
  if (previous && previous.user.id !== response.data.user.id) {
    await Promise.all([setItem('statuses', {}), setItem('heartbeatQueue', []), setItem('flushBackoffUntil', 0)]);
  }

  await setItem('auth', { token: response.data.token, user: response.data.user });

  // Pull the student's enrollments (including ones joined on another device) before the
  // popup renders. If this fails (offline), login still succeeds with whatever is cached.
  await syncEnrollments();
  await broadcast({ type: 'CONTEXT_CHANGED' });

  return { ok: true, state: await buildState() };
}

async function logout(): Promise<ActionResult> {
  const auth = await getItem('auth');

  if (auth) {
    await flushQueue(); // best effort: deliver what we can before the token goes away
    await tryRequest('POST', '/auth/logout', { token: auth.token });
  }

  await Promise.all([
    setItem('auth', null),
    setItem('statuses', {}),
    setItem('heartbeatQueue', []),
    setItem('flushBackoffUntil', 0),
  ]);
  await broadcast({ type: 'CONTEXT_CHANGED' });

  return { ok: true, state: await buildState() };
}

async function join(rawCode: string): Promise<ActionResult> {
  const code = rawCode.trim().toUpperCase();
  if (!code) return fail('Enter the join code from your instructor.');

  const auth = await getItem('auth');
  if (!auth) return fail('Please log in first.');

  const response = await tryRequest<JoinResponse>('POST', '/enrollments/join', { body: { join_code: code }, token: auth.token });

  if (!response) return fail('Cannot reach the server. Check your connection and try again.');
  if (response.status === 401) {
    await setItem('auth', null);
    return fail('Your session expired. Please log in again.');
  }
  if (!response.ok || !response.data) return fail(firstErrorMessage(response.data, 'Could not join that campaign.'));

  const enrolled = toEnrolledCampaign(response.data.data, Date.now());

  await updateItem('enrollments', (current) => {
    const key = String(auth.user.id);
    const others = (current[key] ?? []).filter((c) => c.campaign_id !== enrolled.campaign_id);
    return { ...current, [key]: [...others, enrolled] };
  });
  await broadcast({ type: 'CONTEXT_CHANGED' });

  return { ok: true, notice: `Joined “${enrolled.title}”. Open a lesson on the course site to start.`, state: await buildState() };
}

async function buildState(): Promise<PopupState> {
  const auth = await getItem('auth');
  if (!auth) return { logged_in: false, user: null, campaigns: [], queued: 0 };

  const [enrollments, statuses, queue] = await Promise.all([getItem('enrollments'), getItem('statuses'), getItem('heartbeatQueue')]);

  const campaigns = (enrollments[String(auth.user.id)] ?? [])
    .map((campaign) => ({ ...campaign, status: statuses[String(campaign.campaign_id)] ?? null }))
    .sort((a, b) => a.title.localeCompare(b.title));

  return {
    logged_in: true,
    user: auth.user,
    campaigns,
    queued: queue.filter((item) => item.user_id === auth.user.id).length,
  };
}

/** Tell every open tab (all frames) to re-check whether it should be monitoring. */
async function broadcast(message: TabMessage): Promise<void> {
  const tabs = await chrome.tabs.query({});
  await Promise.all(
    tabs.map(async (tab) => {
      if (tab.id === undefined) return;
      try {
        await chrome.tabs.sendMessage(tab.id, message);
      } catch {
        // No content script in this tab (e.g. chrome:// pages).
      }
    }),
  );
}
