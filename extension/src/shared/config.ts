const configuredBase = import.meta.env.VITE_API_BASE_URL as string | undefined;

/** Laravel API base, e.g. http://127.0.0.1:8000/api/v1 */
export const API_BASE_URL: string = (configuredBase ?? 'http://127.0.0.1:8000/api/v1').replace(/\/+$/, '');

export const REQUEST_TIMEOUT_MS = 10_000;

/** Fallback when the campaign rules don't specify a heartbeat cadence. */
export const DEFAULT_HEARTBEAT_INTERVAL_SECONDS = 15;

/** Videos shorter than this (ads, UI loops, previews) are not treated as lesson videos. */
export const MIN_LESSON_VIDEO_SECONDS = 30;

/** Segments shorter than this are not worth a heartbeat. */
export const MIN_SEGMENT_SECONDS = 0.5;

export const QUEUE_MAX_ITEMS = 500;
/** The server rejects heartbeats older than 24h, so don't hold on to them longer than this. */
export const QUEUE_MAX_AGE_MS = 23 * 60 * 60 * 1000;

export const FLUSH_ALARM = 'cert-monitor-flush';
