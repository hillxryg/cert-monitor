import type { EnrolledCampaign } from './types';

const escapeRegex = (text: string): string => text.replace(/[.+?^${}()|[\]\\]/g, '\\$&');

const globToRegex = (text: string): string => escapeRegex(text).replace(/\*/g, '.*');

function compile(pattern: string): RegExp | null {
  try {
    // Chrome match-pattern style: <scheme>://<host><path>, e.g. https://*.coursera.org/learn/*
    const parts = /^(\*|https?):\/\/([^/]*)(\/.*)?$/i.exec(pattern);

    if (!parts) {
      return new RegExp(`^${globToRegex(pattern)}$`, 'i'); // plain glob over the whole URL
    }

    const scheme = parts[1] === '*' ? 'https?' : escapeRegex(parts[1].toLowerCase());
    const host = parts[2];
    const path = parts[3];

    let hostRegex: string;
    if (host === '*') {
      hostRegex = '[^/]+';
    } else if (host.startsWith('*.')) {
      // "*.example.com" matches example.com and any subdomain.
      hostRegex = `(?:[^/]*\\.)?${escapeRegex(host.slice(2)).replace(/\*/g, '[^/]*')}`;
    } else {
      hostRegex = escapeRegex(host).replace(/\*/g, '[^/]*');
    }

    const pathRegex = path ? globToRegex(path) : '(?:/.*)?';

    return new RegExp(`^${scheme}://${hostRegex}${pathRegex}$`, 'i');
  } catch {
    return null;
  }
}

export function matchesPattern(pattern: string, url: string): boolean {
  const trimmed = pattern.trim();
  if (trimmed === '') return false;

  const regex = compile(trimmed);
  return regex !== null && regex.test(url.replace(/#.*$/, ''));
}

/** First active enrollment whose target_url_pattern matches the URL. */
export function findMatchingCampaign(campaigns: EnrolledCampaign[], url: string): EnrolledCampaign | null {
  return (
    campaigns.find(
      (campaign) =>
        campaign.state === 'active' && campaign.target_url_pattern !== null && matchesPattern(campaign.target_url_pattern, url),
    ) ?? null
  );
}
