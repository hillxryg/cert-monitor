import { MIN_LESSON_VIDEO_SECONDS, MIN_SEGMENT_SECONDS } from '../shared/config';
import { clamp, round2 } from '../shared/num';
import type { VideoTelemetry } from '../shared/types';
import type { FocusGuard, FocusState } from './focus-guard';

type Emit = (telemetry: VideoTelemetry) => void;

/** Conditions that must stay constant for the whole time a segment is reported. */
interface SegmentState {
  playbackRate: number;
  muted: boolean;
  focus: FocusState;
}

/**
 * Reports one <video> as a series of homogeneous segments: [start, end] of media time that
 * was played at one playback rate, with one mute state and one focus state. Whenever any of
 * those change (or the viewer seeks / pauses) the open segment is closed and reported, then
 * a new one begins. The server decides how much of each segment actually counts.
 */
class VideoSession {
  private segmentStart: number | null = null;
  private segmentState: SegmentState | null = null;
  private lastTime = 0;
  private lastTimestamp = 0;
  private timer: number | undefined;
  private readonly subscriptions: Array<[string, EventListener]> = [];

  constructor(
    private readonly video: HTMLVideoElement,
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
      if (!this.video.seeking) this.lastTime = this.video.currentTime;
    });
    // Source changed (next lesson, ad -> content): don't mix positions from different media.
    this.listen('emptied', () => {
      this.flush();
      this.onQualificationChange();
    });
    this.listen('loadstart', () => this.flush());
    this.listen('loadedmetadata', () => {
      this.onQualificationChange();
      this.begin();
    });
    this.listen('durationchange', () => this.onQualificationChange());

    this.begin(); // the video may already be playing when we attach
  }

  /** Long enough to be a lesson video rather than an ad, preview or decorative loop. */
  get qualifies(): boolean {
    const { duration } = this.video;
    return Number.isFinite(duration) && duration >= MIN_LESSON_VIDEO_SECONDS;
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
    for (const [type, handler] of this.subscriptions) this.video.removeEventListener(type, handler);
    this.subscriptions.length = 0;
  }

  private listen(type: string, handler: EventListener): void {
    this.video.addEventListener(type, handler);
    this.subscriptions.push([type, handler]);
  }

  private readState(): SegmentState {
    return {
      playbackRate: clamp(this.video.playbackRate, 0.0625, 16),
      muted: this.video.muted || this.video.volume === 0,
      focus: { ...this.focus.current },
    };
  }

  private begin(): void {
    if (this.segmentStart !== null) return;

    const video = this.video;
    if (video.paused || video.ended || video.seeking || !this.qualifies) return;

    this.segmentStart = video.currentTime;
    this.lastTime = video.currentTime;
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
    const end = this.video.seeking ? this.lastTime : this.video.currentTime;
    const duration = this.video.duration;

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
      kind: 'video',
      client_timestamp: timestamp,
      page_title: document.title.slice(0, 255),
      is_focused: state.focus.focused,
      is_visible: state.focus.visible,
      segment_start: round2(start),
      segment_end: round2(Math.min(end, duration)),
      total_duration: round2(duration),
      playback_rate: state.playbackRate,
      is_muted: state.muted,
      video_index: this.index(),
    });
  }

  private index(): number {
    return Math.max(0, Array.from(document.querySelectorAll('video')).indexOf(this.video));
  }
}

export class VideoTracker {
  private readonly sessions = new Map<HTMLVideoElement, VideoSession>();
  private observer: MutationObserver | null = null;
  private unsubscribeFocus: (() => void) | null = null;
  private lessonVideoCount = 0;
  private intervalMs = 15_000;

  constructor(
    private readonly focus: FocusGuard,
    private readonly emit: Emit,
    /** Called when the number of lesson-length videos in this frame changes. */
    private readonly onLessonVideoChange: (count: number) => void,
  ) {}

  start(intervalSeconds: number): void {
    if (this.observer) return;
    this.intervalMs = Math.max(5, intervalSeconds) * 1000;

    this.scan(document);

    // Players are frequently injected after load (and swapped on SPA navigation).
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
    this.lessonVideoCount = 0;
  }

  private scan(root: ParentNode): void {
    if (root instanceof HTMLVideoElement) this.attach(root);
    root.querySelectorAll('video').forEach((video) => this.attach(video));
  }

  private attach(video: HTMLVideoElement): void {
    if (this.sessions.has(video)) return;

    const session = new VideoSession(video, this.focus, this.intervalMs, this.emit, () => this.refreshQualification());
    this.sessions.set(video, session);
    this.refreshQualification();
  }

  private prune(): void {
    for (const [video, session] of this.sessions) {
      if (!video.isConnected) {
        session.dispose();
        this.sessions.delete(video);
      }
    }
    this.refreshQualification();
  }

  private refreshQualification(): void {
    let count = 0;
    this.sessions.forEach((session) => {
      if (session.qualifies) count++;
    });

    if (count !== this.lessonVideoCount) {
      this.lessonVideoCount = count;
      this.onLessonVideoChange(count);
    }
  }
}
