export type PillTone = 'monitoring' | 'paused' | 'warning';

export interface PillView {
  tone: PillTone;
  text: string;
}

const HOST_ID = 'cert-monitor-pill-host';

// All styling lives inside the shadow root so the host page's CSS can't touch the pill,
// and the pill's CSS can't leak into the page.
const STYLE = `
  .pill {
    display: flex;
    align-items: center;
    gap: 8px;
    max-width: 320px;
    padding: 8px 12px;
    border-radius: 999px;
    background: rgba(17, 24, 39, 0.92);
    color: #fff;
    font: 500 13px/1.2 system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25);
    box-sizing: border-box;
  }
  .dot { flex: none; width: 8px; height: 8px; border-radius: 50%; background: #22c55e; }
  .pill[data-tone="paused"] .dot { background: #f59e0b; }
  .pill[data-tone="warning"] .dot { background: #ef4444; }
  .label { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
`;

/** Floating status pill, bottom-right, rendered inside a closed Shadow DOM root. */
export class OverlayPill {
  private host: HTMLElement | null = null;
  private pill: HTMLElement | null = null;
  private label: HTMLElement | null = null;

  mount(): void {
    if (this.host) return;

    document.getElementById(HOST_ID)?.remove(); // e.g. left over from a reloaded extension

    const host = document.createElement('div');
    host.id = HOST_ID;
    host.style.cssText = 'all: initial; position: fixed; right: 16px; bottom: 16px; z-index: 2147483647; pointer-events: none;';

    const root = host.attachShadow({ mode: 'closed' });

    const style = document.createElement('style');
    style.textContent = STYLE;

    const pill = document.createElement('div');
    pill.className = 'pill';
    pill.dataset.tone = 'monitoring';
    pill.setAttribute('role', 'status');
    pill.setAttribute('aria-live', 'polite');

    const dot = document.createElement('span');
    dot.className = 'dot';

    const label = document.createElement('span');
    label.className = 'label';
    label.textContent = 'Monitoring';

    pill.append(dot, label);
    root.append(style, pill);
    document.documentElement.appendChild(host);

    this.host = host;
    this.pill = pill;
    this.label = label;

    document.addEventListener('fullscreenchange', this.onFullscreenChange);
  }

  update(view: PillView): void {
    if (!this.pill || !this.label) return;
    this.pill.dataset.tone = view.tone;
    if (this.label.textContent !== view.text) this.label.textContent = view.text;
  }

  unmount(): void {
    document.removeEventListener('fullscreenchange', this.onFullscreenChange);
    this.host?.remove();
    this.host = null;
    this.pill = null;
    this.label = null;
  }

  /** Only the fullscreen element's subtree is drawn, so move the pill into it. */
  private readonly onFullscreenChange = (): void => {
    if (!this.host) return;
    const fullscreen = document.fullscreenElement;
    const parent = fullscreen && fullscreen.tagName !== 'VIDEO' ? fullscreen : document.documentElement;
    parent.appendChild(this.host);
  };
}
