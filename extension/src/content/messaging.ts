import type { ContentMessage } from '../shared/types';

/**
 * The only place content scripts talk to the service worker. Content scripts never make
 * network calls themselves; everything goes through chrome.runtime.sendMessage.
 */
export async function sendToBackground<T>(message: ContentMessage): Promise<T | undefined> {
  // After the extension is reloaded or updated, already-injected scripts lose their
  // connection ("Extension context invalidated"); stay quiet instead of throwing.
  if (!chrome.runtime?.id) return undefined;

  try {
    return (await chrome.runtime.sendMessage(message)) as T | undefined;
  } catch {
    return undefined;
  }
}
