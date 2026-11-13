# chatties

Two local LLMs talk to each other about a topic you set once — turn by turn, round by round.

Built for [LM Studio](https://lmstudio.ai/) (OpenAI-compatible `/v1` API). PHP orchestrates the ping-pong and proxies token streaming (SSE) so replies appear live in the browser.

## Requirements

- PHP 8.1+ with cURL
- One or two LM Studio servers reachable from the PHP host

## Quick start

1. Point a vhost/docroot at this folder (e.g. `chatties.test`).
2. Copy `.env.example` → `.env` and set your LM Studio defaults (`CHATTIES_API_URL`, `CHATTIES_MODEL`). Optional: `_A` / `_B` overrides.
3. Start LM Studio’s local server and load a model.
4. Open the site, set the shared topic, optional per-agent roles, and max rounds.
5. Start — agents alternate until the round budget is used up.
6. When a thread is finished, use **Weiterführen** to grant more rounds and optionally add a moderator directive to steer the next turns.

`.env` is gitignored. If PHP runs in a VM and LM Studio on the host, use the host LAN IP (or an SSH tunnel), not `127.0.0.1` inside the VM.

## Layout

| Path | Role |
|------|------|
| `index.php` | UI (CSS/JS inlined) |
| `api/` | `start`, `turn`, `thread`, `list`, `extend` |
| `lib/` | Thread store, LLM client, orchestrator |
| `data/threads/` | Local JSON threads (gitignored) |

## License

Use freely for your own experiments.
