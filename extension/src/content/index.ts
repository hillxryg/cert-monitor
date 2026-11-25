import { DEFAULT_HEARTBEAT_INTERVAL_SECONDS, DEFAULT_IDLE_TIMEOUT_SECONDS } from '../shared/config';
import type { HeartbeatAck, PageContext, PillUpdate, Telemetry, TabMessage } from '../shared/types';
import { FocusGuard } from './focus-guard';
import { sendToBackground } from './messaging';
import { OverlayPill, type PillView } from './overlay-pill';
import { ReadTracker } from './read-tracker';
import { MediaTracker } from './video-tracker';

const WARNING_TTL_MS = 20_000;
const MEDIA_MARKER = 'certMonitorMedia';

function warningText(flags: string[]): string | null {
  if (flags.includes('speed_violation')) return 'Not counting • Speed above limit';
  if (flags.includes('muted_playback')) return 'Not counting • Unmute to earn credit';
  if (flags.includes('seek_skip')) return 'Skipped ahead • Skipped part not counted';
  if (flags.includes('scroll_too_fast')) return 'Scrolling too fast • Take your time';
  if (flags.includes('idle_timeout')) return 'Paused • No activity detected';
  return null;
}

/**
 * Runs in every frame, including nested about:blank / blob: frames (SCORM players).
 * It only asks the service worker whether this tab belongs to one of the student's campaigns;
 * trackers start when it does and stop when it no longer does (SPA navigation away, logout,
 * campaign closed).
 *
 * The status pill exists ONLY in the top window: that is the one place that stays visible
 * regardless of how deeply the lesson content is nested. Nested frames never draw anything;
 * they report interactions and media up to the top frame, and the service worker sends pill
 * updates to frame 0 only.
 */
class ContentController {
  private readonly isTop = window === window.top;
  private readonly focus = new FocusGuard();
  private readonly media = new MediaTracker(
    this.focus,
    (telemetry) => this.emit(telemetry),
    (count) => this.onLocalMediaChange(count),
  );
  private readonly reader = new ReadTracker(this.focus, (telemetry) => this.emit(telemetry));
  private readonly pill = new OverlayPill();

  private active = false;
  private localMediaCount = 0;
  private readerEnabled = false;
  private readonly framesWithMedia = new Set<MessageEventSource>();
  private lastUpdate: PillUpdate | null = null;
  private pillTimer: number | undefined;
  private unsubscribeFocus: (() => void) | null = null;

  constructor() {
    if (this.isTop) {
      // Child frames announce lesson media so the top frame doesn't also report "reading".
      window.addEventListener('message', this.onFrameMessage);
    }
  }

  async refresh(): Promise<void> {
    const context = await sendToBackground<PageContext>({ type: 'GET_PAGE_CONTEXT' });

    if (context?.active) {
      this.activate(
        context.interval_seconds || DEFAULT_HEARTBEAT_INTERVAL_SECONDS,
        context.idle_timeout_seconds || DEFAULT_IDLE_TIMEOUT_SECONDS,
      );
    } else {
      this.deactivate();
    }
  }

  onPillUpdate(update: PillUpdate): void {
    if (!this.isTop) return; // only the top window draws the pill

    this.lastUpdate = update;
    this.renderPill();

    window.clearTimeout(this.pillTimer);
    this.pillTimer = window.setTimeout(() => this.renderPill(), WARNING_TTL_MS + 100);
  }

  private activate(intervalSeconds: number, idleTimeoutSeconds: number): void {
    if (this.active) {
      this.reader.reset(); // SPA moved to another lesson that still matches
      this.lastUpdate = null;
      this.renderPill();
      return;
    }
    this.active = true;

    this.focus.start(idleTimeoutSeconds);
    this.media.start(intervalSeconds);

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

    this.media.stop();
    this.reader.stop();
    this.focus.stop();
    this.unsubscribeFocus?.();
    this.unsubscribeFocus = null;
    this.pill.unmount();
    this.readerEnabled = false;
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

  // ------------------------------------------------ media / reading coordination
  private onLocalMediaChange(count: number): void {
    this.localMediaCount = count;

    if (this.isTop) {
      this.updateReaderEnabled();
    } else {
      window.top?.postMessage({ certMonitor: MEDIA_MARKER, present: count > 0 }, '*');
    }
  }

  private updateReaderEnabled(): void {
    this.readerEnabled = this.localMediaCount === 0 && this.framesWithMedia.size === 0;
    this.reader.setEnabled(this.readerEnabled);
    this.renderPill();
  }

  private readonly onFrameMessage = (event: MessageEvent): void => {
    const data: unknown = event.data;
    if (typeof data !== 'object' || data === null || !event.source || event.source === window) return;

    const message = data as { certMonitor?: unknown; present?: unknown };
    if (message.certMonitor !== MEDIA_MARKER) return;

    if (message.present === true) this.framesWithMedia.add(event.source);
    else this.framesWithMedia.delete(event.source);

    this.updateReaderEnabled();
  };

  // ------------------------------------------------------------------ pill
  private renderPill(): void {
    if (!this.isTop || !this.active) return;

    const { focused, visible, idle } = this.focus.current;
    const update = this.lastUpdate;
    let view: PillView;

    if (!focused || !visible) {
      view = { tone: 'paused', text: 'Paused • Tab Unfocused' };
    } else if (idle && this.readerEnabled) {
      // Idleness only matters for reading: people legitimately watch or listen without touching anything.
      view = { tone: 'paused', text: 'Paused • No Activity' };
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
