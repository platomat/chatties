# chatties

Two local LLMs talk to each other about a topic you set once — turn by turn, round by round.

Built for [LM Studio](https://lmstudio.ai/) (OpenAI-compatible `/v1` API). PHP orchestrates the ping-pong and proxies token streaming (SSE) so replies appear live in the browser.

## Requirements

- PHP 8.1+ with cURL
- One or two LM Studio servers reachable from the PHP host

## Quick start

1. Point a vhost/docroot at this folder (e.g. `chatties.test`).
2. Start LM Studio’s local server and load a model.
3. Open the site, set the shared topic, optional per-agent roles, API URLs, and max rounds.
4. Start — agents alternate until the round budget is used up.

If PHP runs in a VM and LM Studio on the host, use the host LAN IP (or an SSH tunnel), not `127.0.0.1` inside the VM.

## Layout

| Path | Role |
|------|------|
| `index.php` | UI (CSS/JS inlined) |
| `api/` | `start`, `turn`, `thread`, `list` |
| `lib/` | Thread store, LLM client, orchestrator |
| `data/threads/` | Local JSON threads (gitignored) |

## License

Use freely for your own experiments.
