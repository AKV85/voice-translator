# VT-22: reconnect reliability and latency evaluation

This iteration extends PR #21 on `feature/VT-22-conversation-latency-reliability`, starting at `e4df34a`. It supersedes the timing limitations described in the phase-2 notes where indicated below. It does not change the model, voice, synthesis instructions, sample rate or audio quality.

## Reconnection behavior

The Alpine component restores the active room's `{code, token}` from the tab's `sessionStorage` during `init()`. Creating or registering a guest saves the credentials before opening the socket; reconnect and refresh authenticate through the WebSocket join message, never through the guest registration endpoint. Entering the already-saved room code also resumes that participant.

Transport failures retry at 1, 2, 4, 8 and then 15-second intervals, without allocating another participant slot. An unacknowledged join is closed after ten seconds. Explicit Leave clears storage and cancels retries; component destruction on navigation preserves storage. Corrupt/unavailable storage is handled without printing credentials. Without working sessionStorage, the current page works but refresh restoration is unavailable.

An invalid/expired session receives `conversation_session_invalid`, clears its saved credentials and returns to the room screen. A replaced connection receives `conversation_replaced` and stops reconnecting, avoiding two pages repeatedly evicting each other. The server still permits only one host and one guest; knowing a room code alone cannot reclaim a participant. Tokens remain bearer credentials, are not rotated on reconnect, and never appear in URLs or diagnostics.

Replacement retains the registry's exact-connection detach guard. The old connection cannot release a new connection's turn. The peer is informed of replacement so it cancels an interrupted recording/playback instead of retaining a busy UI. Old socket callbacks and cancelled playback callbacks cannot mutate the replacement session. In-progress turns are cancelled, not resumed or replayed after a disconnect. Fresh streams keep PCM order on a new playback generation.

## Local-only diagnostics

Developer Lab HTTP routes and uncredentialed Lab/conversation WebSocket access reject production even if feature flags are accidentally enabled. Public-demo token handling is unchanged. Diagnostic logs and summaries additionally require `CONVERSATION_LATENCY_DEBUG_ENABLED=true` in `APP_ENV=local` (testing is also allowed).

The local conversation page exposes **Local latency diagnostics → Refresh timing summary**, or call the component's `latencySummary()`. Each metric retains the latest 256 valid samples and reports count, median, nearest-rank P95 and maximum. Counts refer to that rolling window. Missing, negative and nonfinite observations are excluded; zero is valid. Sample units differ: server/ACK stages are per segment, existing PCM queue/scheduling stages are per PCM buffer.

Only opaque turn/segment IDs, fixed stage names and numeric durations enter timing diagnostics. Receiver feedback is validated against the exact current sender/receiver pair, all correlation fields, an allowlist of stage names, duplicate suppression and bounded duration values. It cannot send arbitrary diagnostic fields into logs. Relay state is bounded to 128 segment records with a 120-second expiry; disconnect discards matching records. Tokens, text, audio and remote absolute timestamps are excluded.

## Timing definitions

All server/worker intervals now use `hrtime(true)`. Worker startup compares monotonic values from processes on the **same OS host**, not separate machines. Browser intervals use `performance.now()`. Audio scheduling uses differences on the local `AudioContext.currentTime` timeline. No absolute timestamps from different machines or clock domains are subtracted.

| Timing | Boundaries |
| --- | --- |
| `stop_to_control_send_ms` | Source browser Stop action to sending the Stop control, including capture flush/upload queue drain. |
| `stt_completion_ms` | Server receipt of Stop to STT finish return; for incremental STT, Stop to bridge `done` receipt. |
| `queue_wait_ms` | Server final-STT receipt/enqueue to segment dispatch; includes waiting for the preceding segment stream. |
| `startup_ms` | Server dispatch timestamp to worker processing start, including IPC/startup/provider resolution before that boundary. |
| `translation_ms` | Worker processing start to completed translation callback, including local orchestration. |
| `tts_request_ms` | Worker-start offset at initiation of the HTTP speech request. |
| `tts_request_to_headers_ms` | HTTP request initiation to streamed HTTP response availability, before body reading. |
| `tts_request_to_first_audio_ms` | HTTP request initiation to the first nonempty body read. |
| `translation_to_first_pcm_ms` | Translation callback to first provider audio callback, before PCM packetization. |
| `first_pcm_emitted_ms` | Worker-start offset immediately before emitting the first audio JSONL event. |
| `first_audio_to_pcm_emit_ms` | First provider audio callback to first packet emission attempt; isolates packet accumulation. |
| `total_ms` | Worker processing through full synthesis and IPC emission; not playback latency. |
| `final_to_first_pcm_send_ms` | Server final-STT receipt to the first successful WebSocket write's beginning. |
| `stop_to_first_pcm_send_ms` | Server Stop receipt to that first write's beginning, when the write occurs after Stop. |
| `socket_write_ms` | First binary write call duration. Successful return is not a receiver/delivery ACK. |
| `tts_event_to_first_pcm_receipt_ms` | Receiver-local TTS-control receipt to first nonempty PCM frame. |
| `receiver_stop_marker_to_first_pcm_ms` | Receiver-local Stop-marker receipt to first PCM; only available when that order occurs. This excludes the path preceding marker receipt. |
| `pcm_receipt_to_queue_ms` | Receiver-local PCM receipt to conversion/scheduling bookkeeping, per buffer. |
| `queue_to_scheduled_start_estimate_ms` | Scheduled source time minus current audio-context time, per buffer. |
| `first_pcm_receipt_to_playback_start_observed_ms` | Receiver first PCM to observing the running WebAudio timeline reach the first source's scheduled time. |
| `stop_to_first_pcm_received_ack_ms` | Source Stop action to receiving the receiver's first-PCM ACK. |
| `stop_to_playback_scheduled_ack_ms` | Source Stop action to receiving the receiver's first scheduled-source ACK. |
| `stop_to_playback_started_ack_ms` | Source Stop action to receiving the receiver's observed-start ACK. |

The three source ACK intervals measure the complete causal path **plus the return ACK path and callback delay**, using one clock. They are upper-bound observations, not exact one-way latency. A synchronized acoustic one-way measurement is not inferred. Feedback before the receiver has seen the Stop marker is excluded from source Stop-to-ACK statistics: incremental audio can legitimately start before Stop. An eventual ACK must not turn such an early start into a misleading post-Stop sample.

`CONVERSATION_LATENCY worker` logs correlated server/worker durations. `CONVERSATION_LATENCY receiver` logs allowlisted receiver-local receipt/schedule/start durations with the same IDs. No server/browser timestamp subtraction is needed to inspect the stages. The receiver's full server stage summary arrives with segment completion, while playback receipt/scheduling is measured as it happens.

WebAudio has no `AudioBufferSourceNode.onstarted` callback. The new start observer checks when the running audio-context clock reaches the requested start time; it is explicitly a software timeline observation, with event-loop/timer uncertainty, not proof of acoustic speaker output. Cancelled observers are invalidated; disabled/suspended playback does not invent a successful start measurement. The existing `onended` measurement remains separate. AudioContext recreation after refresh still requires the browser's normal user gesture (Enable speaker).

## First PCM buffer evaluation

`ConversationPcmBuffer` preserves every input byte, handles odd network fragments while emitting only aligned PCM16 frames, enforces the audio limit, rejects an odd final stream and flushes a short aligned tail. Only the first packet can differ; subsequent full packets remain 3840 bytes. `CONVERSATION_INITIAL_PCM_BYTES=1920` selects the smaller first packet; the default remains **3840** pending physical-PC acceptance.

Reproduce with `php scripts/benchmarks/conversation-pcm.php` (or `docker exec voice-php php scripts/benchmarks/conversation-pcm.php` in the prepared cloud environment). The script calls no providers. It runs the production packetizer 30 times per case, verifies alignment and exact byte reconstruction, and reports monotonic first-emission intervals. Raw results: [VT-22-pcm-benchmark.json](VT-22-pcm-benchmark.json).

| Controlled input | Initial bytes | Count | Median ms | P95 ms | Maximum ms | Frames / 7680-byte stream |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 480 bytes every 10 ms | 3840 | 30 | 80.617 | 80.678 | 80.793 | 2 |
| 480 bytes every 10 ms | 1920 | 30 | 40.297 | 40.357 | 40.360 | 3 |
| 7680 bytes in one burst | 3840 | 30 | 0.000 | 0.001 | 0.005 | 2 |
| 7680 bytes in one burst | 1920 | 30 | 0.000 | 0.001 | 0.001 | 3 |

The paced case saves about 40.3 ms at this buffering stage, with identical PCM hashes. The burst case shows no meaningful gain. These are controlled packetization results, **not** provider, network, browser or two-PC benchmarks. Smaller first packets add one frame for this stream length and can reduce jitter tolerance; subsequent frames and the browser's existing 40 ms scheduling margin are retained. No quality tradeoff or TTS model/voice change was made.

## Validation and remaining acceptance

Verified in this cloud instance with existing dependencies:

- `npm run test:conversation`: refresh for both roles, storage fallback, reconnect/replacement, stale callbacks, ordered playback, statistics/correlation, ACK clock boundaries and WebAudio start observation.
- `php vendor/bin/pest --compact`: unit/feature suite, including registration limits, invalid/expired authentication, production Lab isolation, aligned packetization, timing feedback sanitization and TTS streaming cancellation/body closure.
- `php vendor/bin/pest tests/Integration --compact`: real WebSocket streaming, cancellation/replacement, timing ACK exchange and two consecutive jobs through actual persistent Artisan workers for each first-buffer size.
- Composer validation, PHPStan, Pint, ESLint, Prettier, Vite production build and `git diff --check`.

Final validation: **177 unit/feature tests passed (1030 assertions), 24 integration tests passed (430 assertions), and 20 Node tests passed**. PHPStan reported no errors; Pint checked 214 files; ESLint, Prettier, Composer validation and Vite build passed. One default-suite test is skipped because benchmark audio fixtures are intentionally local-only. One integration test is opt-in for real providers; no real provider or physical two-PC success is claimed. CI now runs the existing integration directory and frontend lint checks in addition to its prior checks.

Required physical acceptance remains: host and guest refresh while idle and during recording/TTS; network loss and recovery; duplicate-page replacement without reconnect loops; expired room; a third guest attempt; RU→EN, EN→RU and alternating turns; multiple queued final segments; rapid Stop; muted/suspended output; cancellation during TTS; and both buffer settings over representative jittery networks. Record device/network conditions, underruns and per-stage count/median/P95/max. Do not infer a production improvement from this synthetic benchmark. Do not enable Developer Lab in production. PR #21 remains unmerged.

## Changed files in this iteration

- `.github/workflows/ci.yml`
- `app/Console/Commands/Conversation/RunConversationWorkerCommand.php`
- `app/Console/Commands/ServeLivePipelineCommand.php`
- `app/Http/Middleware/EnsureLivePipelineLabEnabled.php`
- `app/Services/Conversation/ConversationLatencyRelay.php`
- `app/Services/Conversation/ConversationPcmBuffer.php`
- `app/Services/Conversation/ConversationTiming.php`
- `app/Services/Conversation/ConversationWebSocketHandler.php`
- `app/Services/Conversation/ConversationWorkerManager.php`
- `app/Services/Speech/OpenAIStreamingTextToSpeechProvider.php`
- `config/conversation.php`
- `docs/conversation/VT-22-pcm-benchmark.json`
- `docs/conversation/VT-22-reliability-and-latency.md`
- `eslint.config.js`
- `resources/js/components/conversation-latency.js`
- `resources/js/components/conversation-pcm-worklet.js`
- `resources/js/components/conversation-recorder.js`
- `resources/js/components/conversation-session.js`
- `resources/views/conversation.blade.php`
- `scripts/benchmarks/conversation-pcm.php`
- `tests/Feature/OpenAIStreamingTextToSpeechProviderTest.php`
- `tests/Feature/Services/Conversation/ConversationReconnectTest.php`
- `tests/Feature/Services/Conversation/ConversationTimingTest.php`
- `tests/Frontend/conversation-latency.test.mjs`
- `tests/Frontend/conversation-session.test.mjs`
- `tests/Integration/ConversationLatencyWebSocketTest.php`
- `tests/Integration/ConversationStreamingArtisanTest.php`
- `tests/Integration/ConversationWebSocketTest.php`
- `tests/Support/Conversation/serve-websocket.php`
- `tests/Support/Conversation/streaming-artisan-worker.php`
- `tests/Unit/ConversationPcmBufferTest.php`
