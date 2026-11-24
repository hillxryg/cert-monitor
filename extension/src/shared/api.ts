import { API_BASE_URL, REQUEST_TIMEOUT_MS } from './config';

export class NetworkError extends Error {}

export interface ApiResponse<T> {
  ok: boolean;
  status: number;
  data: T | null;
}

interface RequestOptions {
  body?: unknown;
  token?: string;
}

/** Throws NetworkError when the server can't be reached (offline, timeout, DNS, CORS). */
export async function apiRequest<T>(method: 'GET' | 'POST', path: string, options: RequestOptions = {}): Promise<ApiResponse<T>> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

  const headers: Record<string, string> = { Accept: 'application/json' };
  if (options.body !== undefined) headers['Content-Type'] = 'application/json';
  if (options.token) headers.Authorization = `Bearer ${options.token}`;

  try {
    const response = await fetch(`${API_BASE_URL}${path}`, {
      method,
      headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      signal: controller.signal,
    });

    let data: T | null = null;
    try {
      data = (await response.json()) as T;
    } catch {
      data = null; // empty or non-JSON body
    }

    return { ok: response.ok, status: response.status, data };
  } catch (error) {
    throw new NetworkError(error instanceof Error ? error.message : 'Network error');
  } finally {
    clearTimeout(timer);
  }
}

/** Like apiRequest, but returns null instead of throwing when the server is unreachable. */
export async function tryRequest<T>(method: 'GET' | 'POST', path: string, options: RequestOptions = {}): Promise<ApiResponse<T> | null> {
  try {
    return await apiRequest<T>(method, path, options);
  } catch (error) {
    if (error instanceof NetworkError) return null;
    throw error;
  }
}

/** Pulls the first human-readable message out of a Laravel error body. */
export function firstErrorMessage(data: unknown, fallback: string): string {
  if (typeof data !== 'object' || data === null) return fallback;
  const body = data as { message?: unknown; errors?: unknown };

  if (typeof body.errors === 'object' && body.errors !== null) {
    for (const messages of Object.values(body.errors as Record<string, unknown>)) {
      if (Array.isArray(messages) && typeof messages[0] === 'string') return messages[0];
    }
  }

  return typeof body.message === 'string' && body.message !== '' ? body.message : fallback;
}
