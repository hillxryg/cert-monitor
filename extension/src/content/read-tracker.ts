import type { ReadingTelemetry } from '../shared/types';
import type { FocusGuard } from './focus-guard';

type Emit = (telemetry: ReadingTelemetry) => void;

function scrollPercent(container: Element): number {
  const scrollable = container.scrollHeight - container.clientHeight;
  if (scrollable <= 4) return 100; // content fits on screen: nothing to scroll
  return Math.min(100, Math.max(0, Math.round((container.scrollTop / scrollable) * 100)));
}

/**
 * For lesson pages without audio/video: reports the deepest scroll position every interval,
 * together with the focus and idle state, so the server can measure focused, non-idle dwell
 * time against scroll depth. Only active while no lesson-length media is present.
 *
 * The idle timer lives in FocusGuard (it needs to see clicks, key presses and scrolls from every
 * frame, including nested slide frames, and report them to the top frame). Any of them resets it;
 * here we only read the result.
 */
export class ReadTracker {
  private started = false;
  private enabled = false;
  private intervalMs = 15_000;
  private timer: number | undefined;
  private lastTimestamp = 0;
  private maxScroll = 0;

  constructor(
    private readonly focus: FocusGuard,
    private readonly emit: Emit,
  ) {}

  start(intervalSeconds: number): void {
    if (this.started) return;
    this.started = true;
    this.intervalMs = Math.max(5, intervalSeconds) * 1000;

    // Capture phase: scroll events from inner scroll containers don't bubble.
    document.addEventListener('scroll', this.onScroll, { capture: true, passive: true });
    this.apply();
  }

  stop(): void {
    if (!this.started) return;
    this.started = false;

    document.removeEventListener('scroll', this.onScroll, { capture: true });
    this.apply();
  }

  /** Turn reading tracking on/off (off while a lesson video is on the page). */
  setEnabled(enabled: boolean): void {
    if (this.enabled === enabled) return;
    this.enabled = enabled;
    this.apply();
  }

  /** New lesson (SPA navigation): start measuring from scratch. */
  reset(): void {
    this.maxScroll = 0;
    if (this.started && this.enabled) this.measureInitial();
  }

  private apply(): void {
    const shouldRun = this.started && this.enabled;

    if (shouldRun && this.timer === undefined) {
      this.measureInitial();
      this.timer = window.setInterval(() => this.tick(), this.intervalMs);
    } else if (!shouldRun && this.timer !== undefined) {
      window.clearInterval(this.timer);
      this.timer = undefined;
    }
  }

  private readonly onScroll = (event: Event): void => {
    const root = document.scrollingElement;
    const target = event.target instanceof Element ? event.target : root;
    if (!target) return;

    // Besides the page itself, only count an inner container that is the page's main scroller.
    if (target !== root && !this.isMainScroller(target)) return;

    this.maxScroll = Math.max(this.maxScroll, scrollPercent(target));
  };

  private isMainScroller(element: Element): boolean {
    return element.scrollHeight > element.clientHeight * 1.5 && element.clientHeight >= window.innerHeight * 0.4;
  }

  private measureInitial(): void {
    const root = document.scrollingElement;
    if (root && root.scrollHeight - root.clientHeight > 4) {
      this.maxScroll = Math.max(this.maxScroll, scrollPercent(root));
      return;
    }

    // The document doesn't scroll: single-page apps often scroll an inner container instead.
    for (const candidate of document.querySelectorAll('main, article, [role="main"]')) {
      if (this.isMainScroller(candidate)) {
        this.maxScroll = Math.max(this.maxScroll, scrollPercent(candidate));
        return;
      }
    }

    this.maxScroll = 100; // short page: fully visible without scrolling
  }

  private tick(): void {
    const timestamp = Math.max(Date.now(), this.lastTimestamp + 1);
    this.lastTimestamp = timestamp;

    const { focused, visible, idle } = this.focus.current;

    this.emit({
      kind: 'reading',
      client_timestamp: timestamp,
      page_title: document.title.slice(0, 255),
      is_focused: focused,
      is_visible: visible,
      scroll_percent: this.maxScroll,
      is_idle: idle,
    });
  }
}
