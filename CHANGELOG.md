# Changelog

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
