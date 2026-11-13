# Background generation jobs + UI reconnect

## Problem

Today each agent turn is bound to a single browser ↔ PHP ↔ LLM HTTP/SSE connection.

If the user reloads or closes the tab while a model is generating:

- the UI disconnects from the stream
- we cancel the in-flight LLM request (current workaround)
- **Continue** starts a **new** request
- there is no way to resume or re-attach to the original generation

That wastes compute on slow local models and makes reloads fragile.

## Goal

Run each turn as a **background job** with a stable ID, so the UI can disconnect and later reconnect without restarting the model call.

## Proposed design

1. **Job store** (start simple: JSON files under `data/jobs/`, later Redis/DB if needed)
   - `jobId`, `threadId`, `status` (`queued|running|done|error|cancelled`)
   - partial `content`, timestamps, error
2. **Worker / long-running PHP process** (or `turn` that detaches) calls the LLM and appends tokens to the job
3. **UI**
   - start turn → get `jobId`
   - subscribe via SSE/polling `api/job.php?id=…`
   - on reload: read thread + active `jobId`, resume listening
4. When job completes → append final message to the thread (same as today), clear active job

## Acceptance criteria

- [x] Reload mid-generation does **not** start a second LLM request
- [x] After reload, the UI shows the same in-progress / completed turn
- [x] Stop/cancel cancels the job and the upstream LLM call
- [x] Stale jobs are cleaned up (timeout / crash recovery)
- [x] Works with the existing round model, extend/nudge, and API keys

## Notes / non-goals (for this issue)

- Multi-user auth
- Horizontal scaling
- Replacing LM Studio / provider APIs

## Current workaround

`beforeunload` warning + abort in-flight curl on client disconnect + `generationId` lock / force-continue after interrupt.
