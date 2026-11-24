const TRACKING_PARAMS = /^(utm_.+|fbclid|gclid|mc_cid|mc_eid|ref|ref_src)$/i;
const MAX_KEY_LENGTH = 255;
const MAX_URL_LENGTH = 2048;

/** Strips the fragment and tracking params and sorts the rest, so one lesson maps to one URL. */
export function normalizeUrl(raw: string): string | null {
  try {
    const url = new URL(raw);
    if (url.protocol !== 'http:' && url.protocol !== 'https:') return null;

    url.hash = '';
    const kept = [...url.searchParams.entries()]
      .filter(([name]) => !TRACKING_PARAMS.test(name))
      .sort(([a], [b]) => a.localeCompare(b));

    url.search = '';
    for (const [name, value] of kept) url.searchParams.append(name, value);

    return url.toString();
  } catch {
    return null;
  }
}

/** Small non-cryptographic hash (cyrb53), used only to shorten very long lesson keys. */
function hashString(text: string): string {
  let h1 = 0xdeadbeef;
  let h2 = 0x41c6ce57;
  for (let i = 0; i < text.length; i++) {
    const code = text.charCodeAt(i);
    h1 = Math.imul(h1 ^ code, 2654435761);
    h2 = Math.imul(h2 ^ code, 1597334677);
  }
  h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909);
  h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909);
  return (4294967296 * (2097151 & h2) + (h1 >>> 0)).toString(36);
}

export interface LessonIdentity {
  key: string;
  url: string | null;
}

interface IdentityInput {
  tabUrl: string;
  frameId: number;
  frameUrl: string | undefined;
  kind: 'video' | 'reading';
  videoIndex: number;
}

/**
 * Builds the stable lesson_key the server groups progress by.
 *
 * Always derived from the *tab* URL (the lesson page), never the frame's own URL, so a
 * video inside a player iframe still belongs to the page the student is on. Suffixes keep
 * different trackers on one page from colliding (the server rejects a content-type change
 * for an existing key).
 */
export function buildLessonIdentity(input: IdentityInput): LessonIdentity | null {
  const url = normalizeUrl(input.tabUrl);
  if (url === null) return null;

  let key = url;

  if (input.frameId !== 0 && input.frameUrl) {
    try {
      key += `#frame:${new URL(input.frameUrl).hostname}`;
    } catch {
      key += '#frame';
    }
  }
  if (input.kind === 'video' && input.videoIndex > 0) key += `#v${input.videoIndex}`;
  if (input.kind === 'reading') key += '#reading';

  if (key.length > MAX_KEY_LENGTH) {
    key = `${key.slice(0, 200)}~${hashString(key)}`;
  }

  return { key, url: url.length <= MAX_URL_LENGTH ? url : null };
}
