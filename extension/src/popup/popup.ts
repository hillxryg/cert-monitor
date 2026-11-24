import type { ActionResult, PopupCampaign, PopupMessage, PopupState } from '../shared/types';

/**
 * Popup UI only. It never touches the network or storage directly: every action is a
 * message to the service worker, which owns the API calls and the persistent state.
 */

function byId<T extends HTMLElement>(id: string): T {
  const element = document.getElementById(id);
  if (!element) throw new Error(`Missing #${id}`);
  return element as T;
}

const els = {
  banner: byId<HTMLParagraphElement>('banner'),
  logout: byId<HTMLButtonElement>('logout'),
  viewLogin: byId<HTMLElement>('view-login'),
  viewMain: byId<HTMLElement>('view-main'),
  loginForm: byId<HTMLFormElement>('login-form'),
  loginEmail: byId<HTMLInputElement>('login-email'),
  loginPassword: byId<HTMLInputElement>('login-password'),
  loginSubmit: byId<HTMLButtonElement>('login-submit'),
  who: byId<HTMLParagraphElement>('who'),
  joinForm: byId<HTMLFormElement>('join-form'),
  joinCode: byId<HTMLInputElement>('join-code'),
  joinSubmit: byId<HTMLButtonElement>('join-submit'),
  campaigns: byId<HTMLUListElement>('campaigns'),
  empty: byId<HTMLParagraphElement>('empty'),
  queueNote: byId<HTMLParagraphElement>('queue-note'),
  syncNote: byId<HTMLParagraphElement>('sync-note'),
};

async function send(message: PopupMessage): Promise<ActionResult> {
  try {
    return (await chrome.runtime.sendMessage(message)) as ActionResult;
  } catch {
    return { ok: false, error: 'The extension is not responding. Please try again.' };
  }
}

// ------------------------------------------------------------- rendering
function showBanner(text: string | undefined, kind: 'error' | 'success'): void {
  if (!text) {
    els.banner.hidden = true;
    return;
  }
  els.banner.textContent = text;
  els.banner.className = `banner ${kind}`;
  els.banner.hidden = false;
}

function element<K extends keyof HTMLElementTagNameMap>(tag: K, className: string, text?: string): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  node.className = className;
  // textContent only: campaign titles come from instructors and must never be parsed as HTML.
  if (text !== undefined) node.textContent = text;
  return node;
}

function timeAgo(timestamp: number): string {
  const seconds = Math.max(0, Math.round((Date.now() - timestamp) / 1000));
  if (seconds < 60) return 'just now';
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) return `${minutes} min ago`;
  const hours = Math.round(minutes / 60);
  return hours < 24 ? `${hours} h ago` : `${Math.round(hours / 24)} d ago`;
}

function describeStatus(campaign: PopupCampaign): { text: string; tone: 'ok' | 'warn' | 'bad' | 'muted' } {
  if (campaign.state === 'closed') return { text: 'Closed by your instructor', tone: 'muted' };
  if (campaign.state === 'not_enrolled') return { text: 'Not enrolled — re-enter the join code', tone: 'bad' };
  if (!campaign.status) return { text: 'Waiting for lesson activity', tone: 'muted' };

  const { overall_pct, integrity_score } = campaign.status;
  const text = `${Math.round(overall_pct)}% verified · integrity ${integrity_score}`;
  return integrity_score < campaign.rules.min_integrity_score
    ? { text: `${text} (below the ${campaign.rules.min_integrity_score} minimum)`, tone: 'bad' }
    : { text, tone: 'ok' };
}

function renderCampaign(campaign: PopupCampaign): HTMLLIElement {
  const item = element('li', 'campaign');
  item.append(element('div', 'campaign-title', campaign.title));

  const meta = [campaign.platform_name, campaign.target_url_pattern].filter((part): part is string => Boolean(part));
  if (meta.length > 0) item.append(element('div', 'campaign-meta', meta.join(' · ')));

  const status = describeStatus(campaign);
  item.append(element('div', `campaign-status ${status.tone}`, status.text));

  if (campaign.status) {
    item.append(element('div', 'campaign-updated', `Updated ${timeAgo(campaign.status.updated_at)}`));
  }

  return item;
}

function render(state: PopupState): void {
  els.viewLogin.hidden = state.logged_in;
  els.viewMain.hidden = !state.logged_in;
  els.logout.hidden = !state.logged_in;

  if (!state.logged_in) return;

  els.who.textContent = state.user ? `Signed in as ${state.user.name}` : '';
  els.campaigns.replaceChildren(...state.campaigns.map(renderCampaign));
  els.empty.hidden = state.campaigns.length > 0;

  els.queueNote.hidden = state.queued === 0;
  if (state.queued > 0) {
    els.queueNote.textContent = `${state.queued} update${state.queued === 1 ? '' : 's'} waiting to sync (offline?). They'll be sent automatically.`;
  }
}

/** Renders whatever is cached right now (instant, works offline). */
async function refresh(): Promise<PopupState | null> {
  const result = await send({ type: 'GET_STATE' });
  if (result.state) {
    render(result.state);
    return result.state;
  }
  showBanner(result.error, 'error');
  return null;
}

function setSyncNote(text: string | null, problem = false): void {
  els.syncNote.hidden = text === null;
  els.syncNote.textContent = text ?? '';
  els.syncNote.className = problem ? 'sync-note problem' : 'sync-note';
}

/** Ask the worker to refresh campaigns from the server, so instructor edits show up. */
async function sync(): Promise<void> {
  setSyncNote('Refreshing campaigns…');

  const result = await send({ type: 'SYNC' });
  if (result.state) render(result.state);

  switch (result.sync_status) {
    case 'offline':
      setSyncNote('Offline — showing your saved campaigns.', true);
      break;
    case 'error':
      setSyncNote("Couldn't refresh campaigns — showing your saved ones.", true);
      break;
    case 'unauthenticated':
      setSyncNote(null);
      showBanner('Your session expired. Please log in again.', 'error');
      break;
    default:
      setSyncNote(null); // synced, or another sync is already running
  }
}

// --------------------------------------------------------------- actions
async function run(button: HTMLButtonElement, message: PopupMessage): Promise<ActionResult> {
  button.disabled = true;
  try {
    const result = await send(message);
    if (result.state) render(result.state);
    showBanner(result.ok ? result.notice : result.error, result.ok ? 'success' : 'error');
    return result;
  } finally {
    button.disabled = false;
  }
}

els.loginForm.addEventListener('submit', (event) => {
  event.preventDefault();
  void run(els.loginSubmit, { type: 'LOGIN', email: els.loginEmail.value, password: els.loginPassword.value }).then((result) => {
    if (result.ok) els.loginPassword.value = '';
  });
});

els.joinForm.addEventListener('submit', (event) => {
  event.preventDefault();
  void run(els.joinSubmit, { type: 'JOIN', joinCode: els.joinCode.value }).then((result) => {
    if (result.ok) els.joinCode.value = '';
  });
});

els.logout.addEventListener('click', () => {
  void run(els.logout, { type: 'LOGOUT' });
});

// Live status: the worker writes new numbers to storage after every heartbeat.
chrome.storage.onChanged.addListener(() => void refresh());

// On open: show the cached campaigns immediately, then refresh them from the server.
void refresh().then((state) => {
  if (state?.logged_in) void sync();
});