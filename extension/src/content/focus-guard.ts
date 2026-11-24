/**
 * Tracks whether the lesson is actually in front of the student:
 *   visible  = document.visibilityState === 'visible'   (tab is the active tab, window not minimized)
 *   focused  = window.hasFocus()                         (the browser window has OS focus)
 *
 * Players are often embedded in cross-origin iframes, where hasFocus() only reflects the
 * iframe itself. The top frame is the authority: it relays its state down to child frames.
 */
export interface FocusState {
  focused: boolean;
  visible: boolean;
}

type Listener = (state: FocusState) => void;

const MARKER = 'certMonitor';

interface RelayMessage {
  certMonitor: 'focus';
  focused: boolean;
  visible: boolean;
}

interface RequestMessage {
  certMonitor: 'focus-request';
}

function isRelay(data: unknown): data is RelayMessage {
  return typeof data === 'object' && data !== null && (data as Record<string, unknown>)[MARKER] === 'focus';
}

function isRequest(data: unknown): data is RequestMessage {
  return typeof data === 'object' && data !== null && (data as Record<string, unknown>)[MARKER] === 'focus-request';
}

export class FocusGuard {
  private readonly isTop = window === window.top;
  private readonly listeners = new Set<Listener>();
  private parentState: FocusState | null = null;
  private state: FocusState;
  private pollTimer: number | undefined;
  private running = false;

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

  start(): void {
    if (this.running) return;
    this.running = true;

    document.addEventListener('visibilitychange', this.refresh);
    window.addEventListener('focus', this.refresh);
    window.addEventListener('blur', this.refresh);
    window.addEventListener('message', this.onMessage);
    // Safety net: focus changes caused by clicking into iframes don't always fire events.
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
    window.clearInterval(this.pollTimer);
    this.pollTimer = undefined;
  }

  private read(): FocusState {
    const visible = document.visibilityState === 'visible';
    const own = document.hasFocus();
    const focused = this.isTop ? own : (this.parentState?.focused ?? own);
    return { focused, visible };
  }

  private readonly refresh = (): void => {
    const next = this.read();
    if (next.focused === this.state.focused && next.visible === this.state.visible) return;

    this.state = next;
    this.relayToChildren();
    this.listeners.forEach((listener) => listener(next));
  };

  private relayMessage(): RelayMessage {
    return { certMonitor: 'focus', focused: this.state.focused, visible: this.state.visible };
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

    if (!this.isTop && isRelay(data) && event.source === window.parent) {
      this.parentState = { focused: data.focused, visible: data.visible };
      this.refresh();
    }
  };
}
