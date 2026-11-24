# Cert Monitor – Chrome extension

Manifest V3 · Vite + `@crxjs/vite-plugin` · strict TypeScript.

```bash
cd extension
cp .env.example .env      # optional: change the API base URL
npm install
npm run dev               # dev build with HMR  → load /dist as an unpacked extension
npm run build             # type-check + production build into /dist
```

Load it via `chrome://extensions` → *Developer mode* → *Load unpacked* → select `extension/dist`.

## How it fits together

| Piece | Responsibility |
| --- | --- |
| `src/popup/*` | UI only. Login / logout / join / campaign list. Talks to the worker via `chrome.runtime.sendMessage`. |
| `src/background/service-worker.ts` | Every network call and all persistent state (`chrome.storage.local`). No in-memory globals. |
| `src/background/queue.ts` | FIFO offline heartbeat queue; retries via `chrome.alarms` with backoff. |
| `src/content/*` | DOM listeners only. `focus-guard`, `video-tracker`, `read-tracker`, `overlay-pill` (closed Shadow DOM). |
| `src/shared/*` | Types, storage helpers, API client, URL-pattern matching, lesson-key building. |

**Enrollment sync.** Campaign settings (URL pattern, rules, open/closed) are cached locally but
refreshed from `GET /api/v1/enrollments` on login and every time the popup opens. If the server is
unreachable the cached copy keeps working. A campaign missing from the response was closed, so it
is kept in the list marked "Closed" and monitoring stops; open tabs re-check immediately when
anything relevant changed.

The extension never computes completion or integrity scores. It reports raw telemetry; the
numbers shown in the pill and popup come from the server's heartbeat responses.

## Testing against a local page

Content scripts run on `https://*/*`. To try a local `http://` demo page, add its origin to both
`host_permissions` and the content script `matches` in `src/manifest.json`, and give your test
campaign a matching `target_url_pattern`.