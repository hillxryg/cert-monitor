// ---------------------------------------------------------------- domain
export interface UserInfo {
  id: number;
  name: string;
  email: string;
  role: string;
}

export interface AuthState {
  token: string;
  user: UserInfo;
}

export interface CampaignRules {
  max_playback_speed: number;
  allow_muted_playback: boolean;
  max_skip_seconds: number;
  required_completion_percent: number;
  min_integrity_score: number;
  heartbeat_interval_seconds: number;
}

export type EnrollmentState = 'active' | 'closed' | 'not_enrolled';

export interface EnrolledCampaign {
  enrollment_id: number;
  campaign_id: number;
  title: string;
  platform_name: string | null;
  target_url_pattern: string | null;
  rules: CampaignRules;
  joined_at: string | null;
  state: EnrollmentState;
  /** Epoch ms when the server last confirmed this enrollment (join or sync). */
  synced_at: number;
}

/** Latest server-verified numbers for a campaign, taken from heartbeat responses. */
export interface CampaignStatus {
  overall_pct: number;
  integrity_score: number;
  lesson_pct: number;
  flags: string[];
  updated_at: number;
}

// ------------------------------------------------------------- API shapes
export interface LoginResponse {
  token: string;
  token_type: string;
  user: UserInfo;
}

/** One enrollment as the API returns it (EnrollmentResource), from both join and index. */
export interface EnrollmentPayload {
  enrollment_id: number;
  joined_at: string | null;
  campaign: {
    id: number;
    title: string;
    platform_name: string | null;
    target_url_pattern: string | null;
    rules: CampaignRules;
  };
}

export interface JoinResponse {
  data: EnrollmentPayload;
}

export interface EnrollmentsResponse {
  data: EnrollmentPayload[];
}

interface HeartbeatCommon {
  campaign_id: number;
  client_timestamp: number;
  lesson_key: string;
  lesson_title: string | null;
  lesson_url: string | null;
  tab_focused: boolean;
  tab_visible: boolean;
}

export interface VideoHeartbeatPayload extends HeartbeatCommon {
  content_type: 'video';
  segment_start: number;
  segment_end: number;
  current_time: number;
  duration: number;
  playback_rate: number;
  muted: boolean;
  is_playing: boolean;
}

export interface ReadingHeartbeatPayload extends HeartbeatCommon {
  content_type: 'reading';
  scroll_percent: number;
}

export type HeartbeatPayload = VideoHeartbeatPayload | ReadingHeartbeatPayload;

export interface HeartbeatResponse {
  accepted: boolean;
  reason?: string;
  credited_seconds: number;
  flags: string[];
  lesson: { completion_percent: number; completed: boolean };
  enrollment: { completion_percent: number; integrity_score: number };
}

export interface QueuedHeartbeat {
  id: string;
  user_id: number;
  tab_id: number | null;
  enqueued_at: number;
  attempts: number;
  payload: HeartbeatPayload;
}

// ------------------------------------------- content script -> background
interface TelemetryBase {
  /** Epoch ms at the end of the reported segment. Strictly increasing per video. */
  client_timestamp: number;
  page_title: string;
  is_focused: boolean;
  is_visible: boolean;
}

export interface VideoTelemetry extends TelemetryBase {
  kind: 'video';
  segment_start: number;
  segment_end: number;
  total_duration: number;
  playback_rate: number;
  is_muted: boolean;
  /** Position of the <video> among the frame's videos, to tell several players apart. */
  video_index: number;
}

export interface ReadingTelemetry extends TelemetryBase {
  kind: 'reading';
  scroll_percent: number;
}

export type Telemetry = VideoTelemetry | ReadingTelemetry;

export type ContentMessage =
  | { type: 'GET_PAGE_CONTEXT' }
  | { type: 'HEARTBEAT'; telemetry: Telemetry };

export interface PageContext {
  active: boolean;
  interval_seconds: number;
}

export interface HeartbeatAck {
  delivered: 'sent' | 'queued' | 'dropped';
  reason?: string;
}

// -------------------------------------------------- popup -> background
export type PopupMessage =
  | { type: 'LOGIN'; email: string; password: string }
  | { type: 'LOGOUT' }
  | { type: 'JOIN'; joinCode: string }
  | { type: 'GET_STATE' }
  | { type: 'SYNC' };

export interface PopupCampaign extends EnrolledCampaign {
  status: CampaignStatus | null;
}

export interface PopupState {
  logged_in: boolean;
  user: UserInfo | null;
  campaigns: PopupCampaign[];
  queued: number;
}

export type SyncStatus = 'synced' | 'offline' | 'error' | 'unauthenticated' | 'busy';

export interface ActionResult {
  ok: boolean;
  error?: string;
  notice?: string;
  state?: PopupState;
  /** Present on SYNC: how refreshing enrollments from the server went. */
  sync_status?: SyncStatus;
}

export type RuntimeMessage = ContentMessage | PopupMessage;

// ---------------------------------------------- background -> tab (frames)
export interface PillUpdate {
  lesson_pct: number;
  overall_pct: number;
  integrity_score: number;
  flags: string[];
  received_at: number;
}

export type TabMessage = { type: 'CONTEXT_CHANGED' } | { type: 'PILL_UPDATE'; update: PillUpdate };