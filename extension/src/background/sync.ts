import { tryRequest } from '../shared/api';
import { enrollmentsChanged, reconcileEnrollments, toEnrolledCampaign } from '../shared/enrollments';
import { getItem, setItem, updateItem } from '../shared/storage';
import type { EnrollmentsResponse, SyncStatus } from '../shared/types';

export interface SyncResult {
  status: SyncStatus;
  /** True when stored enrollments changed in a way that matters (pattern, rules, state, new/removed). */
  changed: boolean;
}

/**
 * Refreshes the stored enrollments from GET /api/v1/enrollments so edits an instructor made
 * to a campaign (target_url_pattern, rules, open/closed) reach the student's extension.
 *
 * If the server can't be reached the cached copy is left untouched: monitoring keeps working
 * offline with the last known rules.
 */
export function syncEnrollments(): Promise<SyncResult> {
  // One sync at a time; a second trigger while one is running just reports "busy".
  return navigator.locks.request('cert-monitor:sync', { ifAvailable: true }, async (lock): Promise<SyncResult> => {
    if (!lock) return { status: 'busy', changed: false };
    return runSync();
  }) as unknown as Promise<SyncResult>;
}

async function runSync(): Promise<SyncResult> {
  const auth = await getItem('auth');
  if (!auth) return { status: 'unauthenticated', changed: false };

  const startedAt = Date.now();
  const response = await tryRequest<EnrollmentsResponse>('GET', '/enrollments', { token: auth.token });

  if (!response) return { status: 'offline', changed: false };

  if (response.status === 401) {
    await setItem('auth', null); // token revoked or expired
    return { status: 'unauthenticated', changed: false };
  }

  if (!response.ok || !response.data || !Array.isArray(response.data.data)) {
    return { status: 'error', changed: false };
  }

  const syncedAt = Date.now();
  const remote = response.data.data.map((payload) => toEnrolledCampaign(payload, syncedAt));

  let changed = false;
  await updateItem('enrollments', (current) => {
    const key = String(auth.user.id);
    const before = current[key] ?? [];
    const after = reconcileEnrollments(before, remote, startedAt);
    changed = enrollmentsChanged(before, after);
    return { ...current, [key]: after };
  });

  return { status: 'synced', changed };
}