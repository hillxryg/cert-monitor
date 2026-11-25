import { DEFAULT_IDLE_TIMEOUT_SECONDS } from '../shared/config';

/**
 * Tracks whether the lesson is actually in front of the student:
 *   visible  = document.visibilityState === 'visible'   (tab is the active tab, window not minimized)
 *   focused  = window.hasFocus()                         (the browser window has OS focus)
 *   idle     = no trusted click / key press / scroll anywhere in the page for the idle timeout
 *
 * Course content is often nested several iframes deep (SCORM players use about:blank / blob:
 * frames). hasFocus() and interaction events only reflect the frame they happen in, so the TOP
 * frame is the authority: child frames report their interactions up to it, and it relays the
 * combined state back down.
 */
export interface FocusState {
  focused: boolean;
  visible: boolean;
  idle: boolean;
}

type Listener = (state: FocusState) => void;

const MARKER = 'certMonitor';
const ACTIVITY_POST_INTERVAL_MS = 1000;

/** Interactions that count as the student being present. `scroll` doesn't bubble, so listen in capture phase. */
const ACTIVITY_EVENTS = ['click', 'keydown', 'pointerdown', 'touchstart', 'wheel', 'scroll'] as const;

interface RelayMessage {
  certMonitor: 'focus';
  focused: boolean;
  visible: boolean;
  idle: boolean;
}

interface RequestMessage {
  certMonitor: 'focus-request';
}

interface ActivityMessage {
  certMonitor: 'activity';
}

function messageType(data: unknown): unknown {
  return typeof data === 'object' && data !== null ? (data as Record<string, unknown>)[MARKER] : undefined;
}

const isRelay = (data: unknown): data is RelayMessage => messageType(data) === 'focus';
const isRequest = (data: unknown): data is RequestMessage => messageType(data) === 'focus-request';
const isActivity = (data: unknown): data is ActivityMessage => messageType(data) === 'activity';

export class FocusGuard {
  private readonly isTop = window === window.top;
  private readonly listeners = new Set<Listener>();
  private parentState: FocusState | null = null;
  private state: FocusState;
  private pollTimer: number | undefined;
  private running = false;

  private idleTimeoutMs = Number.POSITIVE_INFINITY;
  private lastActivity = Date.now();
  private lastActivityPost = 0;

  constructor() {
    this.state = this.read();
  }

  get current(): FocusState {
    return this.state;
  }

  subscribe(listener: Listener): () => void {
    this.listeners.add(listener);
    return () => {
      this.listeners.delete(listener);
    };
  }

  start(idleTimeoutSeconds: number = DEFAULT_IDLE_TIMEOUT_SECONDS): void {
    if (this.running) return;
    this.running = true;

    this.idleTimeoutMs = Math.max(10, idleTimeoutSeconds) * 1000;
    this.lastActivity = Date.now();

    document.addEventListener('visibilitychange', this.refresh);
    window.addEventListener('focus', this.refresh);
    window.addEventListener('blur', this.refresh);
    window.addEventListener('message', this.onMessage);
    for (const type of ACTIVITY_EVENTS) {
      document.addEventListener(type, this.onActivityEvent, { capture: true, passive: true });
    }
    // Safety net: focus changes caused by clicking into iframes don't always fire events,
    // and idleness is a matter of time passing, not of an event.
    this.pollTimer = window.setInterval(this.refresh, 1000);

    if (!this.isTop) {
      const request: RequestMessage = { certMonitor: 'focus-request' };
      window.parent.postMessage(request, '*');
    }

    this.refresh();
    this.relayToChildren();
  }

  stop(): void {
    if (!this.running) return;
    this.running = false;

    document.removeEventListener('visibilitychange', this.refresh);
    window.removeEventListener('focus', this.refresh);
    window.removeEventListener('blur', this.refresh);
    window.removeEventListener('message', this.onMessage);
    for (const type of ACTIVITY_EVENTS) {
      document.removeEventListener(type, this.onActivityEvent, { capture: true });
    }
    window.clearInterval(this.pollTimer);
    this.pollTimer = undefined;
    this.idleTimeoutMs = Number.POSITIVE_INFINITY;
  }

  /** The student interacted with the page (in this frame). Resets the idle timer. */
  noteActivity(): void {
    const now = Date.now();

    if (this.isTop) {
      this.lastActivity = now;
      this.refresh();
      return;
    }

    // Nested frame: tell the top frame, which owns the idle clock (throttled).
    if (now - this.lastActivityPost >= ACTIVITY_POST_INTERVAL_MS) {
      this.lastActivityPost = now;
      const message: ActivityMessage = { certMonitor: 'activity' };
      window.top?.postMessage(message, '*');
    }
  }

  private read(): FocusState {
    const visible = document.visibilityState === 'visible';
    const own = document.hasFocus();

    if (this.isTop) {
      return { focused: own, visible, idle: Date.now() - this.lastActivity > this.idleTimeoutMs };
    }

    const parent = this.parentState;
    return { focused: parent ? parent.focused : own, visible, idle: parent ? parent.idle : false };
  }

  private readonly refresh = (): void => {
    const next = this.read();
    const prev = this.state;
    if (next.focused === prev.focused && next.visible === prev.visible && next.idle === prev.idle) return;

    this.state = next;
    this.relayToChildren();
    this.listeners.forEach((listener) => listener(next));
  };

  private readonly onActivityEvent = (event: Event): void => {
    // Scripted events (isTrusted === false) must not keep a page "alive".
    if (event.isTrusted) this.noteActivity();
  };

  private relayMessage(): RelayMessage {
    return { certMonitor: 'focus', focused: this.state.focused, visible: this.state.visible, idle: this.state.idle };
  }

  private relayToChildren(): void {
    const message = this.relayMessage();
    document.querySelectorAll('iframe').forEach((frame) => {
      frame.contentWindow?.postMessage(message, '*');
    });
  }

  private readonly onMessage = (event: MessageEvent): void => {
    const data: unknown = event.data;

    if (isRequest(data) && event.source && event.source !== window) {
      // A child frame asked for the current state.
      (event.source as Window).postMessage(this.relayMessage(), '*');
      return;
    }

    if (this.isTop && isActivity(data) && event.source && event.source !== window) {
      this.lastActivity = Date.now(); // a nested slide frame was clicked / typed in / scrolled
      this.refresh();
      return;
    }

    if (!this.isTop && isRelay(data) && event.source === window.parent) {
      this.parentState = { focused: data.focused, visible: data.visible, idle: data.idle };
      this.refresh();
    }
  };
}
