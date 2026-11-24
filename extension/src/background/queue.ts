import { apiRequest, NetworkError } from '../shared/api';
import { QUEUE_MAX_AGE_MS, QUEUE_MAX_ITEMS } from '../shared/config';
import { getItem, setItem, updateItem } from '../shared/storage';
import type {
  AuthState,
  CampaignStatus,
  EnrollmentState,
  HeartbeatResponse,
  PillUpdate,
  QueuedHeartbeat,
  TabMessage,
} from '../shared/types';

/**
 * Offline-tolerant heartbeat delivery. The queue lives in chrome.storage.local and is
 * strictly FIFO: the server ignores any heartbeat older than the last one it accepted,
 * so a newer heartbeat must never overtake an older one that is still waiting.
 */

type Outcome = 'sent' | 'drop' | 'retry';

export async function enqueueHeartbeat(item: QueuedHeartbeat): Promise<void> {
  await updateItem('heartbeatQueue', (queue) =>
    queue.length >= QUEUE_MAX_ITEMS ? [...queue.slice(queue.length - QUEUE_MAX_ITEMS + 1), item] : [...queue, item],
  );
}

export async function flushQueue(): Promise<void> {
  // The lock keeps concurrent triggers (message handler + alarm) from sending in parallel.
  await navigator.locks.request('cert-monitor:flush', async () => {
    for (;;) {
      const [auth, queue, backoffUntil] = await Promise.all([
        getItem('auth'),
        getItem('heartbeatQueue'),
        getItem('flushBackoffUntil'),
      ]);

      const head = queue[0];
      if (!head || !auth) return;
      if (Date.now() < backoffUntil) return;

      // Stale, or queued by a different account: never send these.
      if (Date.now() - head.enqueued_at > QUEUE_MAX_AGE_MS || head.user_id !== auth.user.id) {
        await removeFromQueue(head.id);
        continue;
      }

      const outcome = await sendOne(auth, head);

      if (outcome === 'retry') {
        const attempts = head.attempts + 1;
        await updateItem('heartbeatQueue', (current) =>
          current.map((item) => (item.id === head.id ? { ...item, attempts } : item)),
        );
        await setItem('flushBackoffUntil', Date.now() + backoffMs(attempts));
        return;
      }

      if (backoffUntil !== 0) await setItem('flushBackoffUntil', 0);
      await removeFromQueue(head.id);
    }
  });
}

function backoffMs(attempts: number): number {
  return Math.min(30_000 * 2 ** (attempts - 1), 10 * 60_000);
}

async function removeFromQueue(id: string): Promise<void> {
  await updateItem('heartbeatQueue', (queue) => queue.filter((item) => item.id !== id));
}

async function sendOne(auth: AuthState, item: QueuedHeartbeat): Promise<Outcome> {
  let response;
  try {
    response = await apiRequest<HeartbeatResponse>('POST', '/heartbeats', { body: item.payload, token: auth.token });
  } catch (error) {
    if (error instanceof NetworkError) return 'retry';
    throw error;
  }

  if (response.status === 401) {
    // Token revoked or expired: sign out locally so the popup asks the student to log in again.
    await setItem('auth', null);
    return 'drop';
  }

  if (response.status === 403 || response.status === 404) {
    // 403: campaign closed. 404: no enrollment for this campaign.
    await markCampaignState(auth.user.id, item.payload.campaign_id, response.status === 403 ? 'closed' : 'not_enrolled');
    return 'drop';
  }

  if (response.status === 429 || response.status >= 500) return 'retry';
  if (!response.ok) return 'drop'; // 422 etc.: resending the same payload can't succeed

  const data = response.data;
  if (!data || data.accepted === false) return 'drop'; // duplicate / out-of-order / stale

  await recordResult(item, data);
  return 'sent';
}

async function markCampaignState(userId: number, campaignId: number, state: EnrollmentState): Promise<void> {
  await updateItem('enrollments', (current) => {
    const key = String(userId);
    const list = current[key];
    if (!list) return current;

    return { ...current, [key]: list.map((c) => (c.campaign_id === campaignId ? { ...c, state } : c)) };
  });
}

async function recordResult(item: QueuedHeartbeat, data: HeartbeatResponse): Promise<void> {
  const now = Date.now();

  const status: CampaignStatus = {
    overall_pct: data.enrollment.completion_percent,
    integrity_score: data.enrollment.integrity_score,
    lesson_pct: data.lesson.completion_percent,
    flags: data.flags,
    updated_at: now,
  };

  await updateItem('statuses', (current) => ({ ...current, [String(item.payload.campaign_id)]: status }));

  await notifyPill(item.tab_id, {
    lesson_pct: status.lesson_pct,
    overall_pct: status.overall_pct,
    integrity_score: status.integrity_score,
    flags: status.flags,
    received_at: now,
  });
}

async function notifyPill(tabId: number | null, update: PillUpdate): Promise<void> {
  if (tabId === null) return;

  const message: TabMessage = { type: 'PILL_UPDATE', update };
  try {
    // The pill only lives in the top frame.
    await chrome.tabs.sendMessage(tabId, message, { frameId: 0 });
  } catch {
    // Tab closed or navigated away; nothing to update.
  }
}
