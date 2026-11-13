# chatties

Two LLMs talk to each other about a topic you set once — turn by turn, round by round.

Works with any OpenAI-compatible `/v1` chat API (local or remote). PHP runs each turn as a **background job** so you can reload and reconnect without restarting the model call.

## Requirements

- PHP 8.1+ with cURL (CLI `php` available for the job worker)
- One or two OpenAI-compatible API endpoints reachable from the PHP host

## Quick start

1. Point a vhost/docroot at this folder (e.g. `chatties.test`).
2. Copy `.env.example` → `.env` and set defaults (`CHATTIES_API_URL`, `CHATTIES_MODEL`, optional `CHATTIES_API_KEY`). Optional: `_A` / `_B` overrides.
3. Make sure your API server(s) are running and reachable.
4. Open the site, set the shared topic, optional per-agent roles/keys, and max rounds.
5. Start — agents alternate until the round budget is used up. Reload mid-turn: the UI reconnects to the same job.
6. When a thread is finished, use **Continue further** to grant more rounds and optionally add a nudge to steer the next turns.

`.env` is gitignored. If PHP runs in a VM and the API is on the host, use the host LAN IP (or an SSH tunnel), not `127.0.0.1` inside the VM.

For slow models, raise `CHATTIES_HTTP_TIMEOUT` (seconds; `0` = unlimited; default `1800`) and optionally lower `CHATTIES_MAX_TOKENS` (default `1024`). Job heartbeats use `CHATTIES_JOB_STALE_SECONDS` (default `120`) for crash recovery. If the web SAPI cannot find CLI PHP, set `CHATTIES_PHP_BIN`.

Note: API keys entered in the UI are stored in local thread JSON under `data/threads/` (also gitignored).

## Layout

| Path | Role |
|------|------|
| `index.php` | UI (CSS/JS inlined) |
| `api/` | `start`, `turn`, `job`, `thread`, `list`, `extend` |
| `bin/run-job.php` | CLI worker for background generations |
| `lib/` | Thread/job stores, LLM client, orchestrator |
| `data/threads/` | Local JSON threads (gitignored) |
| `data/jobs/` | Local JSON generation jobs (gitignored) |

## License

Use freely for your own experiments.
