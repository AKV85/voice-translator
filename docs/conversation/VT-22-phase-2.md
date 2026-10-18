# VT-22 phase 2: segment correlation and browser timing

Follow-up to [PR #21 review](https://github.com/AKV85/voice-translator/pull/21#issuecomment-6249892426).
This does not complete the manual VT-22 acceptance gate or claim improved real-world latency.

## Correlation and compatibility

Each acquired microphone turn gets an opaque random 128-bit `conversation_turn_id`.
Each final incremental STT segment gets an independent opaque `segment_id` and a
one-based `segment_index` when the server receives the final STT event, before
queueing it. The nonincremental async path creates one segment after finalization.
IDs contain no room code, token, text, language, participant, or machine identity.

The pending worker record carries all three fields. IPC's existing `turn_id`
continues to mean its unique worker job ID; it equals `segment_id`, not the
microphone turn ID. Existing worker protocol, binary PCM frames, message types,
and fields are preserved. Correlation fields are additive on translation,
TTS-start and segment-completion messages, and on final incremental transcripts.
Turn-start messages carry `conversation_turn_id`. Server timing logs include both
turn and segment identity, so consecutive segments cannot be conflated.

The browser checks turn identity, increasing segment index, and the sequence
translation -> TTS-start -> completion for the receiving client. The speaking
client independently checks translation order because audio events go only to
the peer. Duplicate, stale, skipped and reordered
correlated control events are ignored. Legacy messages without any correlation
fields remain accepted. PCM binary frames retain WebSocket's in-order delivery;
there is no per-frame identifier, so arbitrary same-stream PCM reordering cannot
be detected independently. Worker protocol validation remains responsible for
invalid event order, wrong IDs and duplicate completion upstream.

Successful turn completion lets scheduled audio drain, even while a following
turn begins. Abort notifications add `aborted: true`; abort, error, peer loss and
disconnect stop queued sources, clear segment state and invalidate callbacks.
Old socket callbacks cannot reset a replacement connection. Cleanup does not
erase previously collected valid aggregate samples.

## Opt-in and privacy

`CONVERSATION_LATENCY_DEBUG_ENABLED` defaults to false. When explicitly enabled
for a local lab, server timing logs and the browser tracker are enabled. Completed
segment events then include `server_latency` with worker duration fields.
The conversation page passes this flag through existing Alpine configuration.
No new route, public diagnostic endpoint, telemetry upload, or Lab exposure is
added. The browser no longer unconditionally logs first PCM receipt.

Call the Alpine component's `latencySummary()` in a local debugging session to
read aggregates. Only fixed stage names and numeric durations are retained there;
no transcripts, audio, credentials, room codes or participant data are logged.
In-flight correlation records are bounded to 128; each stage retains the latest
256 valid observations. Count/median/P95/max describe this rolling window, not an
unbounded all-time population. Missing, null, negative and nonfinite observations
are excluded; a real zero remains a sample. Median averages the middle two for an
even count; P95 is nearest-rank `sorted[ceil(0.95 * count) - 1]`.

## Time bases and stage definitions

Server-local elapsed times use `hrtime(true)` nanoseconds converted to milliseconds.
Client times use `performance.now()` milliseconds. WebAudio's `currentTime` is in
seconds on its own audio-context timeline; only its local scheduling difference
is converted to milliseconds. Never subtract a server timestamp from a browser
timestamp, or timestamps from different PCs.

| Stage | Sample unit | Meaning and limitations |
| --- | --- | --- |
| `server_startup_ms` | segment | Wall-clock cross-process worker startup estimate; clock adjustments can invalidate it; invalid negative values are excluded by aggregation |
| `server_translation_ms` | segment | Worker start to translation callback, monotonic; includes local orchestration |
| `server_translation_to_first_pcm_ms` | segment | Translation callback to first nonempty provider PCM, before IPC buffering |
| `server_total_ms` | segment | Worker processing through synthesis/IPC emission; not browser playback |
| `tts_event_to_first_pcm_receipt_ms` | segment | Browser receipt of TTS-start control to first nonempty PCM frame; includes buffering, server sending and network effects, not isolated network latency |
| `pcm_receipt_to_queue_ms` | PCM buffer | Browser receipt to buffer conversion/queue bookkeeping |
| `queue_to_scheduled_start_estimate_ms` | PCM buffer | Predicted delay from current audio-context time to scheduled source start; explicitly an estimate, **not confirmed audible start** |
| `first_pcm_receipt_to_playback_end_callback_ms` | fully drained segment | First PCM receipt to final `AudioBufferSourceNode.onended` callback, after server completion; includes local queue wait and event-loop delay |

The source-end callback indicates WebAudio source completion, not confirmation
that a physical speaker finished producing sound. OS/device output latency,
muting, suspended contexts and acoustic start are not directly observable here.
Muted/suspended output produces no scheduled/end sample. Aborted in-flight
segments produce no end sample. Previously completed timing observations remain.
No source-speech-to-remote-acoustic end-to-end measurement is claimed.

Additional debug server logs use the same correlation fields: `stt_finish`
measures blocking STT finalization for the nonincremental path;
`final_to_translation_delivery_ms` measures final-STT receipt to translation event
handling, including queue waiting; `final_to_first_pcm_send` measures final-STT
receipt to the first socket-write attempt. Those log durations are not browser
receipt acknowledgements and are not included in browser aggregate stages.
Incremental finalization itself still needs provider/capture timing evidence.

## Regression checks and validation

- Native Node tests (`npm run test:conversation`): eight deterministic mock tests
  cover multiple segments, stale/reordered events, statistics including missing
  values and rolling windows, buffer scheduling without overlap, delayed source
  completion, abort/disconnect cleanup, stale callbacks and default-off behavior.
- Added Pest coverage for opaque correlation, segment order within one turn,
  reset after abort/disconnect, and correlation continuity in the existing real
  WebSocket integration test using fake providers.
- Existing CI workflow now invokes the Node test command before its existing
  frontend build. No workflow was added and no existing checks were weakened.
- Local Node tests, frontend build, changed-JS ESLint/Prettier and diff whitespace
  inspection passed. Full ESLint still reports the three existing AudioWorklet
  global errors; full Prettier still reports unchanged
  `conversation-pcm-worklet.js`. The touched recorder was formatted and now passes.
- Host PHP remains unavailable; Pest, PHPStan, Pint and PHP syntax checks were not
  executed locally. Existing CI must validate them for this follow-up commit.

## Separate manual acceptance gate

The [first-iteration checklist](VT-22-first-iteration.md#remaining-limitations-and-manual-two-pc-validation)
still applies: 30+ physical two-PC turns, multiple final segments, rapid
Start/Stop, reconnects, provider errors, worker timeouts and orphan-process checks.
Collect actual stage observations and network/device conditions; report per-stage
sample counts and percentile summaries. Do not mix segment and buffer samples
or report mock timing values as benchmarks. No paid provider calls, production
changes, deployment, merge or real two-PC benchmark occurred in this work.
