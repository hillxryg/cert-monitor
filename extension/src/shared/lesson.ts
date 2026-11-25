// Volatile params that change between visits to the same lesson (sesskey is Moodle's per-session token).
const TRACKING_PARAMS = /^(utm_.+|fbclid|gclid|mc_cid|mc_eid|ref|ref_src|sesskey)$/i;
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
export function hashString(text: string): string {
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
  /** The module this item belongs to: the lesson page itself. */
  group: string;
  url: string | null;
}

interface IdentityInput {
  tabUrl: string;
  frameId: number;
  frameUrl: string | undefined;
  kind: 'media' | 'reading';
  mediaId: string | null;
}

function shorten(text: string): string {
  return text.length > MAX_KEY_LENGTH ? `${text.slice(0, 200)}~${hashString(text)}` : text;
}

function hostnameOf(url: string | undefined): string {
  if (!url) return '';
  try {
    return new URL(url).hostname; // '' for about:blank, blob:, data:
  } catch {
    return '';
  }
}

/**
 * Builds the stable lesson_key (one tracked item) and lesson_group (its module).
 *
 * Both derive from the *tab* URL (the lesson page), never the frame's own URL, so media inside
 * player iframes - including about:blank / blob: frames whose URLs are meaningless or change on
 * every load - still belongs to the page the student is on. Each media element adds its own
 * suffix: narrated slides must not share a timeline, or watching one slide would "verify" every
 * slide of the same length. Reading items get a suffix so they never collide with media (the
 * server rejects a content-type change for an existing key).
 */
export function buildLessonIdentity(input: IdentityInput): LessonIdentity | null {
  const url = normalizeUrl(input.tabUrl);
  if (url === null) return null;

  let key = url;

  const frameHost = input.frameId !== 0 ? hostnameOf(input.frameUrl) : '';
  if (frameHost !== '') key += `#frame:${frameHost}`;

  if (input.kind === 'media' && input.mediaId) key += `#m:${input.mediaId}`;
  if (input.kind === 'reading') key += '#reading';

  return { key: shorten(key), group: shorten(url), url: url.length <= MAX_URL_LENGTH ? url : null };
}
