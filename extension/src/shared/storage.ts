import type { AuthState, CampaignStatus, EnrolledCampaign, QueuedHeartbeat } from './types';

/**
 * Everything the extension remembers lives in chrome.storage.local, because the MV3
 * service worker can be terminated at any moment and loses all in-memory state.
 */
export interface StorageSchema {
  auth: AuthState | null;
  /** Enrollments keyed by user id, so logging back in as the same student keeps them. */
  enrollments: Record<string, EnrolledCampaign[]>;
  /** Latest verified numbers keyed by campaign id (current user only). */
  statuses: Record<string, CampaignStatus>;
  /** Offline heartbeat queue, oldest first. */
  heartbeatQueue: QueuedHeartbeat[];
  /** Don't hit the network again before this epoch ms (backoff after a failure). */
  flushBackoffUntil: number;
}

const DEFAULTS: StorageSchema = {
  auth: null,
  enrollments: {},
  statuses: {},
  heartbeatQueue: [],
  flushBackoffUntil: 0,
};

export async function getItem<K extends keyof StorageSchema>(key: K): Promise<StorageSchema[K]> {
  const result = await chrome.storage.local.get(key);
  const stored = result[key] as StorageSchema[K] | undefined;
  return stored ?? structuredClone(DEFAULTS[key]);
}

export async function setItem<K extends keyof StorageSchema>(key: K, value: StorageSchema[K]): Promise<void> {
  await chrome.storage.local.set({ [key]: value });
}

/**
 * Atomic read-modify-write. A Web Lock serializes concurrent updates of the same key
 * (many messages can hit the worker at once); the lock holds no state of its own.
 * `transform` must be pure: return a new value, don't mutate the one it receives.
 */
export function updateItem<K extends keyof StorageSchema>(
  key: K,
  transform: (current: StorageSchema[K]) => StorageSchema[K],
): Promise<StorageSchema[K]> {
  return navigator.locks.request(`cert-monitor:${key}`, async () => {
    const next = transform(await getItem(key));
    await setItem(key, next);
    return next;
  }) as unknown as Promise<StorageSchema[K]>;
}