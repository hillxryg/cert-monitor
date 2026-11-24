import type { EnrolledCampaign, EnrollmentPayload } from './types';

/** Maps an API enrollment (join or index response) to what the extension stores. */
export function toEnrolledCampaign(payload: EnrollmentPayload, syncedAt: number): EnrolledCampaign {
  return {
    enrollment_id: payload.enrollment_id,
    campaign_id: payload.campaign.id,
    title: payload.campaign.title,
    platform_name: payload.campaign.platform_name,
    target_url_pattern: payload.campaign.target_url_pattern,
    rules: payload.campaign.rules,
    joined_at: payload.joined_at,
    state: 'active',
    synced_at: syncedAt,
  };
}

/**
 * Merges the server's current list of open enrollments into the locally stored list.
 *
 *  - Campaigns the server returned replace the stored copy (new pattern, new rules, active again).
 *  - Active campaigns the server did not return were closed (or left their date window): they
 *    stay in the list, marked closed, so the popup can say so and monitoring stops.
 *  - A campaign the student joined *after* this sync request started is left alone: the
 *    response can't know about it yet, and must not mark it closed.
 *  - Campaigns that are new to this device (e.g. joined on another computer) are appended.
 */
export function reconcileEnrollments(
  local: EnrolledCampaign[],
  remote: EnrolledCampaign[],
  syncStartedAt: number,
): EnrolledCampaign[] {
  const remoteById = new Map(remote.map((campaign) => [campaign.campaign_id, campaign]));
  const seen = new Set<number>();

  const merged = local.map((item): EnrolledCampaign => {
    const fresh = remoteById.get(item.campaign_id);
    if (fresh) {
      seen.add(item.campaign_id);
      return fresh;
    }
    if (item.state === 'active' && item.synced_at < syncStartedAt) {
      return { ...item, state: 'closed' };
    }
    return item;
  });

  for (const item of remote) {
    if (!seen.has(item.campaign_id)) merged.push(item);
  }

  return merged;
}

/** True when two lists differ in anything that matters (ignores the synced_at bookkeeping). */
export function enrollmentsChanged(before: EnrolledCampaign[], after: EnrolledCampaign[]): boolean {
  const strip = (list: EnrolledCampaign[]): string =>
    JSON.stringify(
      list.map((item) => {
        const { synced_at: _syncedAt, ...rest } = item;
        return rest;
      }),
    );

  return strip(before) !== strip(after);
}
