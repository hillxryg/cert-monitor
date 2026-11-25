# Cert Monitor – Project Instructions & Architecture Spec

## Overview
**Cert Monitor** is a student certification integrity-monitoring platform consisting of:
1. **Laravel Backend (`backend/`)**: REST API (`v1`), Sanctum token authentication, server-side segment merging (`SegmentMerger`), anti-cheat rule enforcement, and an Instructor web dashboard (Blade + Tailwind CSS).
2. **Manifest V3 Chrome Extension (`extension/`)**: Strict TypeScript + Vite (`@crxjs/vite-plugin`), `chrome.storage.local` persistence, `navigator.locks` atomic updates, offline FIFO heartbeat queue (`chrome.alarms`), and a closed Shadow DOM status pill.

---

## Current Milestone Status (Committed & Passing)
- **Backend (`backend/`)**: All **60 feature and unit tests (196 assertions)** pass via `php artisan test`.
  - `POST /api/v1/auth/login` & `POST /api/v1/auth/logout` (Sanctum tokens)
  - `POST /api/v1/enrollments/join` (idempotent join by `join_code`)
  - `GET /api/v1/enrollments` (`EnrollmentIndexTest` – returns authenticated student's currently open enrollments with up-to-date `target_url_pattern` and `rules`, matching the exact payload shape of `/join`)
  - `POST /api/v1/heartbeats` (`HeartbeatTest` – server-side segment merging, completion percentage, speed/mute/focus/skip violation logging, deduplication, and integrity score calculation)
- **Chrome Extension (`extension/`)**: Compiles cleanly via `npm run build` (`tsc --noEmit && vite build`) into `extension/dist/`.
  - **Enrollment Sync & Reconciliation (`src/background/sync.ts`, `src/shared/enrollments.ts`)**:
    - Syncs from `GET /api/v1/enrollments` on login and every time the popup opens.
    - Uses `reconcileEnrollments()` with `synced_at` timestamps to prevent race conditions when joining a campaign while a sync request is in flight.
    - Marks campaigns omitted by the server as `state: 'closed'` ("Closed by your instructor") and immediately notifies open lesson tabs to re-evaluate URL matching when campaign rules or patterns change.
    - Leaves cached enrollments untouched when offline so monitoring continues uninterrupted.

---

## Engineering Conventions & Local Environment Constraints
1. **TypeScript `navigator.locks.request` Typing**:
   - The local `typescript` version in `extension/node_modules` uses an older `lib.dom.d.ts` where `navigator.locks.request()` with an `async` callback infers `Promise<Promise<T>>` instead of `Promise<T>`.
   - Always append `as unknown as Promise<ReturnType>` to `navigator.locks.request(...)` calls (already applied in `src/shared/storage.ts` and `src/background/sync.ts`). Do not upgrade `typescript` in `extension/package.json`.
2. **PHPUnit Strict JSON Assertions**:
   - In Laravel feature tests using `assertJsonPath()`, assert integer `1` rather than float `1.0` when verifying JSON-encoded whole numbers (as seen in `EnrollmentIndexTest.php`).
3. **Server-Side Source of Truth**:
   - The extension never computes completion percentages or integrity scores locally; it sends raw telemetry heartbeats and renders the server's verified numbers.
   - All extension state lives in `chrome.storage.local` via `src/shared/storage.ts` (never rely on in-memory service worker globals).

---

## Next Task (Queued): Multi-Module SCORM & Fortinet Support
Fortinet Training Institute (`training.fortinet.com/mod/scorm/...`) runs Moodle + Articulate Storyline SCORM packages that render SVG `<image>` slides with `<audio>` narration inside nested `about:blank` / `blob:` iframes rather than `<video>` elements.

When resuming development, implement:
1. **SCORM Frame Injection (`extension/src/manifest.json`)**:
   - Add `"match_about_blank": true` and `"match_origin_as_fallback": true` under `content_scripts` so nested SCORM iframes receive the content script.
2. **Audio Narration Media Tracking (`extension/src/content/video-tracker.ts`)**:
   - Extend media detection to observe both `HTMLVideoElement` and `HTMLAudioElement` (`duration > 5`) so narrated SCORM slides use the exact same segment merging, `playbackRate` checks, mute checks, and tab-focus rules as HTML5 video.
3. **Slide Interaction & Top-Frame Pill (`extension/src/content/read-tracker.ts`, `overlay-pill.ts`)**:
   - Reset the idle timer on `click`, `pointerdown`, and `keydown` inside slide frames (in addition to `scroll`) for silent/quiz slides.
   - Ensure the floating Shadow DOM status pill mounts in the top window (`window.top`) so it stays visible outside nested SCORM iframes.
4. **Multi-Module Certification Progress (`backend/`)**:
   - Optionally add a nullable `expected_lessons` integer column to `campaigns` so instructors can define total module counts for multi-module certifications (e.g., 12 Fortinet modules) when calculating overall completion.