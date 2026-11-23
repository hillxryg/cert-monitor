# Project: Online Certification Anti-Cheat Monitor

## Overview
A two-part system that verifies whether students genuinely watch videos and read lessons on third-party online certification platforms without skipping, speeding up, or leaving tabs idle:
1. `backend/` — Laravel 12 web application (Instructor/Student dashboard) + Sanctum REST API.
2. `extension/` — Chrome Manifest V3 extension (Vite + CRXJS + TypeScript) that tracks `<video>` playback, scroll dwell time, and tab visibility.

## Architecture & Security Rules
- **Zero Client-Side Trust:** The Chrome extension NEVER computes final completion or integrity scores. It sends 15-second heartbeat intervals to `/api/v1/heartbeats`.
- **Interval Merging:** All watched video timestamps must be stored as JSON ranges (`[[start, end], ...]`) in `lesson_progress.watched_ranges` and merged server-side in `App\Services\SegmentMerger` so re-watching the same 10 seconds does not inflate completion percentage.
- **Shadow DOM Isolation:** Any UI injected into third-party course pages (`overlay-pill.ts`) MUST render inside a closed/open Shadow DOM root so host site CSS never breaks the extension pill.
- **Manifest V3 Compliance:** Never use persistent background variables in `service-worker.ts`; persist auth tokens and offline heartbeat queues in `chrome.storage.local` and flush via `chrome.alarms`.

## Coding Conventions
- **Backend:** Keep controllers thin; put interval-merging and integrity deduction logic inside `app/Services/`. Validate all API payloads using Laravel FormRequests.
- **Extension:** Strict TypeScript (`noImplicitAny: true`). Separate DOM listeners (`content/`) from network calls (`background/service-worker.ts`) using `chrome.runtime.sendMessage`.

## First Milestone Build Order
1. Laravel migrations, models, Sanctum API login, and `POST /api/v1/heartbeats` with `SegmentMerger` unit tests.
2. Instructor Web Dashboard (create campaign, generate `join_code`, view student roster & `integrity_logs`).
3. Chrome Extension popup login + `video-tracker.ts` + `focus-guard.ts` + Shadow DOM status pill.