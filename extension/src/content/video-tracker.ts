import { MIN_MEDIA_SECONDS, MIN_SEGMENT_SECONDS } from '../shared/config';
import { hashString } from '../shared/lesson';
import { clamp, round2 } from '../shared/num';
import type { MediaTelemetry } from '../shared/types';
import type { FocusGuard, FocusState } from './focus-guard';

type Emit = (telemetry: MediaTelemetry) => void;

/** Conditions that must stay constant for the whole time a segment is reported. */
interface SegmentState {
  playbackRate: number;
  muted: boolean;
  focus: FocusState;
}

/**
 * Stable id for one media element within the page. Each narrated slide keeps its own timeline:
 * if slides shared one, hearing a 20s slide would "verify" every other 20s slide too.
 *
 *  - http(s) source: hash of origin + path (query strings often carry rotating tokens).
 *  - blob:/data:/MSE sources change on every load, so fall back to duration + position in the page.
 */
function mediaIdFor(media: HTMLMediaElement): string {
  const tag = media instanceof HTMLVideoElement ? 'v' : 'a';
  const source = media.currentSrc || media.src;

  if (/^https?:/i.test(source)) {
    try {
      const url = new URL(source);
      return `${tag}-${hashString(url.origin + url.pathname)}`;
    } catch {
      // fall through to the structural id
    }
  }

  const siblings = Array.from(document.querySelectorAll(media instanceof HTMLVideoElement ? 'video' : 'audio'));
  return `${tag}-d${Math.round(media.duration)}-i${Math.max(0, siblings.indexOf(media))}`;
}

/**
 * Reports one <video> or <audio> element as a series of homogeneous segments: [start, end] of
 * media time played at one playback rate, with one mute state and one focus state. Whenever any
 * of those change (or the viewer seeks / pauses) the open segment is closed and reported, then a
 * new one begins. The server decides how much of each segment actually counts.
 *
 * Narrated SCORM slides (<audio>) follow exactly the same rules as <video>.
 */
class MediaSession {
  private segmentStart: number | null = null;
  private segmentState: SegmentState | null = null;
  private lastTime = 0;
  private lastTimestamp = 0;
  private timer: number | undefined;
  private readonly subscriptions: Array<[string, EventListener]> = [];

  constructor(
    private readonly media: HTMLMediaElement,
    private readonly focus: FocusGuard,
    private readonly intervalMs: number,
    private readonly emit: Emit,
    private readonly onQualificationChange: () => void,
  ) {
    this.listen('playing', () => this.begin());
    this.listen('pause', () => this.flush());
    this.listen('ended', () => this.flush());
    this.listen('seeking', () => this.flush()); // closes the segment at the pre-seek position
    this.listen('seeked', () => this.begin());
    this.listen('ratechange', () => this.restart());
    this.listen('volumechange', () => this.onEnvironmentChange());
    this.listen('timeupdate', () => {
      if (!this.media.seeking) this.lastTime = this.media.currentTime;
    });
    // Source changed (next slide, ad -> content): don't mix positions from different media.
    this.listen('emptied', () => {
      this.flush();
      this.onQualificationChange();
    });
    this.listen('loadstart', () => this.flush());
    this.listen('loadedmetadata', () => {
      this.onQualificationChange();
      this.begin();
    });
    this.listen('durationchange', () => {
      this.onQualificationChange();
      this.begin(); // duration may only become known (and long enough) after playback started
    });

    this.begin(); // the media may already be playing when we attach
  }

  /**
   * Long enough to be lesson content: rules out UI sounds and tiny clips, live streams (infinite
   * duration) and looping decorative backgrounds.
   */
  get qualifies(): boolean {
    const { duration, loop } = this.media;
    return Number.isFinite(duration) && duration > MIN_MEDIA_SECONDS && !loop;
  }

  /** Focus guard changed: close the segment if the conditions it was recorded under changed. */
  onEnvironmentChange(): void {
    if (this.segmentStart === null || this.segmentState === null) return;

    const now = this.readState();
    const was = this.segmentState;
    const same =
      now.playbackRate === was.playbackRate &&
      now.muted === was.muted &&
      now.focus.focused === was.focus.focused &&
      now.focus.visible === was.focus.visible;

    if (!same) this.restart();
  }

  dispose(): void {
    this.flush();
    for (const [type, handler] of this.subscriptions) this.media.removeEventListener(type, handler);
    this.subscriptions.length = 0;
  }

  private listen(type: string, handler: EventListener): void {
    this.media.addEventListener(type, handler);
    this.subscriptions.push([type, handler]);
  }

  private readState(): SegmentState {
    return {
      playbackRate: clamp(this.media.playbackRate, 0.0625, 16),
      muted: this.media.muted || this.media.volume === 0,
      focus: { ...this.focus.current },
    };
  }

  private begin(): void {
    if (this.segmentStart !== null) return;

    const media = this.media;
    if (media.paused || media.ended || media.seeking || !this.qualifies) return;

    this.segmentStart = media.currentTime;
    this.lastTime = media.currentTime;
    this.segmentState = this.readState();
    this.timer = window.setInterval(() => this.restart(), this.intervalMs);
  }

  private restart(): void {
    this.flush();
    this.begin();
  }

  private flush(): void {
    if (this.segmentStart === null || this.segmentState === null) return;

    const start = this.segmentStart;
    const state = this.segmentState;
    // While a seek is in flight currentTime is already the *destination*; use the last played position.
    const end = this.media.seeking ? this.lastTime : this.media.currentTime;
    const duration = this.media.duration;

    this.segmentStart = null;
    this.segmentState = null;
    if (this.timer !== undefined) {
      window.clearInterval(this.timer);
      this.timer = undefined;
    }

    if (!Number.isFinite(duration) || end - start < MIN_SEGMENT_SECONDS) return;

    // Strictly increasing so the server never mistakes two close heartbeats for duplicates.
    const timestamp = Math.max(Date.now(), this.lastTimestamp + 1);
    this.lastTimestamp = timestamp;

    this.emit({
      kind: 'media',
      media: this.media instanceof HTMLVideoElement ? 'video' : 'audio',
      media_id: mediaIdFor(this.media),
      client_timestamp: timestamp,
      page_title: document.title.slice(0, 255),
      is_focused: state.focus.focused,
      is_visible: state.focus.visible,
      segment_start: round2(start),
      segment_end: round2(Math.min(end, duration)),
      total_duration: round2(duration),
      playback_rate: state.playbackRate,
      is_muted: state.muted,
    });
  }
}

export class MediaTracker {
  private readonly sessions = new Map<HTMLMediaElement, MediaSession>();
  private observer: MutationObserver | null = null;
  private unsubscribeFocus: (() => void) | null = null;
  private lessonMediaCount = 0;
  private intervalMs = 15_000;

  constructor(
    private readonly focus: FocusGuard,
    private readonly emit: Emit,
    /** Called when the number of lesson-length media elements in this frame changes. */
    private readonly onLessonMediaChange: (count: number) => void,
  ) {}

  start(intervalSeconds: number): void {
    if (this.observer) return;
    this.intervalMs = Math.max(5, intervalSeconds) * 1000;

    this.scan(document);

    // Players are frequently injected after load (and swapped on SPA navigation / slide changes).
    this.observer = new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        mutation.addedNodes.forEach((node) => {
          if (node instanceof Element) this.scan(node);
        });
      }
      this.prune();
    });
    this.observer.observe(document.documentElement, { childList: true, subtree: true });

    this.unsubscribeFocus = this.focus.subscribe(() => {
      this.sessions.forEach((session) => session.onEnvironmentChange());
    });
  }

  stop(): void {
    this.observer?.disconnect();
    this.observer = null;
    this.unsubscribeFocus?.();
    this.unsubscribeFocus = null;

    this.sessions.forEach((session) => session.dispose());
    this.sessions.clear();
    this.lessonMediaCount = 0;
  }

  private scan(root: ParentNode): void {
    if (root instanceof HTMLMediaElement) this.attach(root);
    root.querySelectorAll('video, audio').forEach((element) => {
      if (element instanceof HTMLMediaElement) this.attach(element);
    });
  }

  private attach(media: HTMLMediaElement): void {
    if (this.sessions.has(media)) return;

    const session = new MediaSession(media, this.focus, this.intervalMs, this.emit, () => this.refreshQualification());
    this.sessions.set(media, session);
    this.refreshQualification();
  }

  private prune(): void {
    for (const [media, session] of this.sessions) {
      if (!media.isConnected) {
        session.dispose();
        this.sessions.delete(media);
      }
    }
    this.refreshQualification();
  }

  private refreshQualification(): void {
    let count = 0;
    this.sessions.forEach((session) => {
      if (session.qualifies) count++;
    });

    if (count !== this.lessonMediaCount) {
      this.lessonMediaCount = count;
      this.onLessonMediaChange(count);
    }
  }
}
