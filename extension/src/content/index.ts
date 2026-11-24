import { DEFAULT_HEARTBEAT_INTERVAL_SECONDS } from '../shared/config';
import type { HeartbeatAck, PageContext, PillUpdate, Telemetry, TabMessage } from '../shared/types';
import { FocusGuard } from './focus-guard';
import { sendToBackground } from './messaging';
import { OverlayPill, type PillView } from './overlay-pill';
import { ReadTracker } from './read-tracker';
import { VideoTracker } from './video-tracker';

const WARNING_TTL_MS = 20_000;
const VIDEO_MARKER = 'certMonitorVideo';

function warningText(flags: string[]): string | null {
  if (flags.includes('speed_violation')) return 'Not counting • Speed above limit';
  if (flags.includes('muted_playback')) return 'Not counting • Unmute to earn credit';
  if (flags.includes('seek_skip')) return 'Skipped ahead • Skipped part not counted';
  if (flags.includes('scroll_too_fast')) return 'Scrolling too fast • Take your time';
  return null;
}

/**
 * Runs in every frame. It only asks the service worker whether this tab belongs to one of
 * the student's campaigns; trackers start when it does and stop when it no longer does
 * (SPA navigation away, logout, campaign closed).
 */
class ContentController {
  private readonly isTop = window === window.top;
  private readonly focus = new FocusGuard();
  private readonly video = new VideoTracker(
    this.focus,
    (telemetry) => this.emit(telemetry),
    (count) => this.onLocalVideoChange(count),
  );
  private readonly reader = new ReadTracker(this.focus, (telemetry) => this.emit(telemetry));
  private readonly pill = new OverlayPill();

  private active = false;
  private localVideoCount = 0;
  private readonly framesWithVideo = new Set<MessageEventSource>();
  private lastUpdate: PillUpdate | null = null;
  private pillTimer: number | undefined;
  private unsubscribeFocus: (() => void) | null = null;

  constructor() {
    if (this.isTop) {
      // Child frames announce lesson videos so the top frame doesn't also report "reading".
      window.addEventListener('message', this.onFrameMessage);
    }
  }

  async refresh(): Promise<void> {
    const context = await sendToBackground<PageContext>({ type: 'GET_PAGE_CONTEXT' });

    if (context?.active) {
      this.activate(context.interval_seconds || DEFAULT_HEARTBEAT_INTERVAL_SECONDS);
    } else {
      this.deactivate();
    }
  }

  onPillUpdate(update: PillUpdate): void {
    this.lastUpdate = update;
    this.renderPill();

    window.clearTimeout(this.pillTimer);
    this.pillTimer = window.setTimeout(() => this.renderPill(), WARNING_TTL_MS + 100);
  }

  private activate(intervalSeconds: number): void {
    if (this.active) {
      this.reader.reset(); // SPA moved to another lesson that still matches
      this.lastUpdate = null;
      this.renderPill();
      return;
    }
    this.active = true;

    this.focus.start();
    this.video.start(intervalSeconds);

    if (this.isTop) {
      this.reader.start(intervalSeconds);
      this.updateReaderEnabled();

      this.pill.mount();
      this.unsubscribeFocus = this.focus.subscribe(() => this.renderPill());
      this.renderPill();
    }
  }

  private deactivate(): void {
    if (!this.active) return;
    this.active = false;

    this.video.stop();
    this.reader.stop();
    this.focus.stop();
    this.unsubscribeFocus?.();
    this.unsubscribeFocus = null;
    this.pill.unmount();
    this.lastUpdate = null;
    window.clearTimeout(this.pillTimer);
  }

  private emit(telemetry: Telemetry): void {
    void sendToBackground<HeartbeatAck>({ type: 'HEARTBEAT', telemetry }).then((ack) => {
      // The worker no longer considers this page monitored (logged out, URL changed): re-check.
      if (ack?.delivered === 'dropped' && (ack.reason === 'no_campaign' || ack.reason === 'logged_out')) {
        void this.refresh();
      }
    });
  }

  // ------------------------------------------------ video / reading coordination
  private onLocalVideoChange(count: number): void {
    this.localVideoCount = count;

    if (this.isTop) {
      this.updateReaderEnabled();
    } else {
      window.top?.postMessage({ certMonitor: VIDEO_MARKER, present: count > 0 }, '*');
    }
  }

  private updateReaderEnabled(): void {
    this.reader.setEnabled(this.localVideoCount === 0 && this.framesWithVideo.size === 0);
  }

  private readonly onFrameMessage = (event: MessageEvent): void => {
    const data: unknown = event.data;
    if (typeof data !== 'object' || data === null || !event.source || event.source === window) return;

    const message = data as { certMonitor?: unknown; present?: unknown };
    if (message.certMonitor !== VIDEO_MARKER) return;

    if (message.present === true) this.framesWithVideo.add(event.source);
    else this.framesWithVideo.delete(event.source);

    this.updateReaderEnabled();
  };

  // ------------------------------------------------------------------ pill
  private renderPill(): void {
    if (!this.isTop || !this.active) return;

    const { focused, visible } = this.focus.current;
    const update = this.lastUpdate;
    let view: PillView;

    if (!focused || !visible) {
      view = { tone: 'paused', text: 'Paused • Tab Unfocused' };
    } else {
      const fresh = update !== null && Date.now() - update.received_at < WARNING_TTL_MS;
      const warning = fresh ? warningText(update.flags) : null;

      view = warning
        ? { tone: 'warning', text: warning }
        : { tone: 'monitoring', text: `Monitoring • Verified ${Math.floor(update?.lesson_pct ?? 0)}%` };
    }

    this.pill.update(view);
  }
}

// ------------------------------------------------------------------ boot
const controller = new ContentController();
void controller.refresh();

chrome.runtime.onMessage.addListener((message: TabMessage) => {
  if (message.type === 'CONTEXT_CHANGED') void controller.refresh();
  else if (message.type === 'PILL_UPDATE') controller.onPillUpdate(message.update);
});

if (window === window.top) {
  // Single-page course sites change the URL without reloading; notice and re-check.
  let lastHref = location.href;
  window.setInterval(() => {
    if (location.href !== lastHref) {
      lastHref = location.href;
      void controller.refresh();
    }
  }, 1000);
}
