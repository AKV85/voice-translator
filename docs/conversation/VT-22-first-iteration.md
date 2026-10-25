# VT-22: initial latency and reliability regression coverage

Issue: https://akotovlaknojus.atlassian.net/browse/VT-22

Base: verified main commit `f6c42a4793e8947704586ed08b031adbed1298b2`.
This is a focused first iteration, not completion of VT-22 acceptance criteria.

## Architecture and findings

`ServeLivePipelineCommand` coordinates authenticated conversation sockets, STT
sessions, incremental segment queues, and exclusive microphone turns. Final STT
segments are processed sequentially by `ConversationWorkerManager` (or its
persistent pool). `RunConversationWorkerCommand` translates and streams PCM16
through newline-delimited IPC. The server forwards PCM to the peer; the browser
schedules it with Web Audio. Pending segments must complete before turn release.

Existing instrumentation logs stop-to-translation, stop-to-first-PCM, worker
startup, and translation elapsed times. The worker also emits a completion timing
summary, but the nonpersistent manager rebuilt completion without that summary.
This silently prevented the existing server worker-summary logger from running.
Persistent workers already forward the completion event, including its timings.

## Changes

- Preserve only known nonnegative integer/null worker duration fields through
  successful nonpersistent completion. Release timing state on completion,
  cancellation, and protocol/process failure. Older workers without timings work.
- Correlate the server's worker summary log with `turn_id` in both modes.
- Use monotonic `hrtime` for worker-local durations. Keep wall time only for the
  cross-process startup estimate. Ignore empty TTS callbacks when measuring first
  PCM; they are not audio.
- Add mock subprocess regression cases for ordered byte delivery, one completion,
  metadata preservation/filtering, reuse without stale metadata, invalid order,
  duplicate completion, incorrect byte count, stale turn IDs, and failed exit.
- Add a turn-handler regression for notification failure during abort, repeated
  abort after the peer takes ownership, and reacquisition after cleanup.

No provider configuration, frontend behavior, or CI workflow changes.

## Timing interpretation

The `CONVERSATION_LATENCY worker` structured entry contains `turn_id` and:

| Field | Meaning |
| --- | --- |
| `startup_ms` | Wall-clock estimate from server worker request to worker processing start; null if unavailable |
| `translation_ms` | Worker processing start to translation callback |
| `translation_to_first_pcm_ms` | Translation callback to first nonempty provider PCM callback |
| `total_ms` | Worker processing start through synthesis and IPC audio emission |

These are server measurements in milliseconds, not remote audible latency.
Translation timing includes local orchestration; first PCM precedes the worker's
80 ms IPC chunk buffering. Total time includes synthesis and synchronous IPC
writes. The existing server stop-to-first-PCM log runs before its socket write;
it does not prove browser receipt or playback. Startup estimates can be affected
by wall-clock adjustments. Persistent-worker timing forwarding is unchanged.

Metrics contain durations and an opaque correlation ID, not speech, audio, or
credentials. Existing transcript/translation protocol messages remain unchanged.

## Validation in this environment

- `git diff --check`: passed.
- `npm run build`: passed; optional fontaine optimization warning only.
- `node_modules/.bin/eslint resources/js`: failed on three existing undefined
  AudioWorklet globals in `conversation-pcm-worklet.js` (`AudioWorkletProcessor`,
  `sampleRate`, `registerProcessor`). File matches base byte-for-byte.
- `node_modules/.bin/prettier resources/js --check`: failed on existing formatting
  in `conversation-pcm-worklet.js` and `conversation-recorder.js`; both match base.
- Pest, PHPStan, Pint, and PHP syntax checks: **not executed**. Host PHP is absent
  and Docker is inaccessible. No privileged runtime operations were attempted.

The existing `.github/workflows/ci.yml` runs Pest, PHPStan, Pint, Composer
validation, and the frontend build on pull requests. CI must validate PHP changes
before review/merge. ESLint and Prettier are local documented checks, not existing
CI steps. No checks were weakened and no new workflow was added.

## Remaining limitations and manual two-PC validation

No real latency samples were collected and no performance improvement is claimed.
STT finalization latency, browser receive/playback acknowledgements, cross-machine
clock correlation, and median/P95/max aggregation remain follow-up work. Do not
subtract clocks from different PCs. For future measured samples, group each stage
separately, report sample count, median, nearest-rank P95, and maximum; do not treat
missing measurements as zero. This iteration does not change persistent-pool
validation or prove absence of orphaned processes across every failure path.

On an explicitly authorized local lab with two physical PCs:

1. Complete at least 30 alternating Host/Guest turns, including short/long speech
   and multiple final STT segments. Verify segment order, no duplicate playback
   or overlap, and that the next speaker starts only after pending work ends.
2. Exercise rapid Start/Stop and disconnect/reconnect during capture,
   translation, and streaming. Verify old sockets/events cannot affect a new turn.
3. Inject STT/translation/TTS failure and worker timeout/cancellation. Confirm
   pending registries clear, turn ownership recovers, and workers terminate.
4. Capture correlated server timings and browser receive, first audible playback,
   and playback completion separately. Record network/browser/device conditions,
   sample counts and median/P95/max per stage; identify bottlenecks from evidence.

These real-provider tests were not run here. No paid provider calls, deployment,
production infrastructure changes, merge, or Developer Lab exposure occurred.
