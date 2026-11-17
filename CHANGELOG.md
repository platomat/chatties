# Changelog

## 0.8.1 — 2026-11-17

### Features

- Render finished agent replies as Markdown (local `marked` + `DOMPurify`; stream stays plain text)

## 0.8.0 — 2026-11-13

### Features

- Background generation jobs: each turn runs detached from the browser SSE connection
- UI reconnect after reload (`activeJobId` + `api/job.php` stream)
- Stop cancels the job and the upstream LLM request
- Stale job cleanup via heartbeat / `CHATTIES_JOB_STALE_SECONDS`

## 0.7.0 — 2026-11-13

### Features

- English / German UI with language switcher (preference in `localStorage`)

## 0.6.0 — 2026-11-13

### Features

- Optional per-agent API key (Bearer), with `.env` defaults
- Longer LLM HTTP timeout via `CHATTIES_HTTP_TIMEOUT` (default 1800s)
- Provider-agnostic wording (any OpenAI-compatible URL)

## 0.5.1 — 2026-11-13

### Fixes / UX

- Extend panel only when a thread is `done` (and respects `[hidden]` with CSS)
- After reload/error: resume hint + button at the end of the chat instead of a fake “thinking” bubble
- Unlock stuck `running` threads on load; remember last thread id in `sessionStorage`

## 0.5.0 — 2026-11-13

### Features

- Local `.env` defaults for API URL and model (`CHATTIES_API_URL`, `CHATTIES_MODEL`, optional `_A`/`_B`)
- `.env.example` committed; `.env` stays out of git

## 0.4.0 — 2026-11-13

### Features

- Extend finished (or paused) threads with extra rounds
- Optional moderator directive to steer the next turns
- Nudges appear as dashed “Impuls” / “Nudge” bubbles in the transcript

## 0.3.0 — 2026-11-13

### Features

- Token streaming from LM Studio through PHP SSE (`api/turn.php`)
- Live bubble updates with typing-dots cursor while an agent writes
- Stop aborts the in-flight stream request

## 0.2.0 — 2026-11-13

### Features

- Thread history: list past dialogs, open by click, deep-link via `?t=<id>`
- Continue unfinished / errored threads; done threads stay read-only
- Refresh control for the history list

## 0.1.0 — 2026-11-13

### Features

- Dual-agent dialog via LM Studio OpenAI-compatible API (`/v1/chat/completions`)
- Shared topic / question for both agents
- Per-agent name, API URL, optional model ID, and role context
- Round model: one round = every agent speaks once; configurable max rounds
- Agents receive remaining rounds / turns in the prompt so they can conclude
- Turn-by-turn UI: each reply appears when that agent finishes
- Typing / thinking indicator while the next agent generates
- Stop button to halt after the current turn
- Threads stored as local JSON under `data/threads/`
- Branding link back to the start page
- No external CDN scripts or fonts
