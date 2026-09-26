<?php

namespace App\Services\Translation;

use App\Contracts\RealtimeTranslationProvider;
use App\Contracts\StreamingWebSocketConnection;
use App\Contracts\StreamingWebSocketFactory;
use App\DTO\RealtimeTranslationResult;
use App\Enums\Language;
use App\Exceptions\RealtimeTranslationException;
use Closure;
use Generator;
use JsonException;
use Throwable;

final readonly class OpenAIRealtimeTranslationProvider implements RealtimeTranslationProvider
{
    private const EVENT_LOOP_TIMEOUT_SECONDS = 0.005;

    private const FINAL_OUTPUT_TIMEOUT_SECONDS = 20.0;

    private const PCM_SAMPLE_RATE = 24000;

    private const PCM_BYTES_PER_SAMPLE = 2;

    private const AUDIBLE_FRAME_DURATION_MS = 10;

    /**
     * Approximately -40 dBFS for signed 16-bit PCM.
     */
    private const AUDIBLE_RMS_THRESHOLD = 328.0;

    public function __construct(
        private StreamingWebSocketFactory $webSocketFactory,
        private string $apiKey,
        private string $endpoint = 'wss://api.openai.com/v1/realtime/translations',
        private string $model = 'gpt-realtime-translate',
    ) {}

    public function translate(
        iterable $pcm16Chunks,
        Language $sourceLanguage,
        Language $targetLanguage,
        ?Closure $onSourceTranscript = null,
        ?Closure $onTranslatedTranscript = null,
        ?Closure $onTranslatedAudio = null,
    ): RealtimeTranslationResult {
        if ($sourceLanguage === $targetLanguage) {
            throw new RealtimeTranslationException(
                'Source and target languages must be different.',
            );
        }

        $connection = null;

        $startedAt = microtime(true);

        try {
            $connection = $this->webSocketFactory->connect(
                url: $this->buildUrl(),
                headers: [
                    'Authorization' => "Bearer {$this->apiKey}",
                ],
                timeoutSeconds: 30,
            );

            $chunks = $this->chunkIterator(
                $pcm16Chunks,
            );

            $chunks->rewind();

            $sessionConfigured = false;
            $sessionCloseSent = false;
            $audioWasSent = false;

            $nextChunkAt = null;
            $lastChunkEndsAt = null;
            $finalDeadline = null;

            $sourceTranscript = '';
            $translatedTranscript = '';
            $translatedAudio = '';

            /*
             * Used only for incremental audible-audio detection.
             *
             * Complete 10 ms PCM frames are consumed from this buffer.
             * Any incomplete frame remains here until the next delta arrives.
             */
            $audibilityBuffer = '';

            $firstSourceTranscriptMs = null;
            $firstTranslatedTranscriptMs = null;

            $firstInputAudioSentMs = null;
            $lastInputAudioSentMs = null;
            $inputFinishedMs = null;

            $firstAudioPacketMs = null;
            $firstAudibleAudioAvailableMs = null;

            $sessionClosedMs = null;

            $connection->onText(
                function (
                    string $message,
                ) use (
                    $connection,
                    $startedAt,
                    $onSourceTranscript,
                    $onTranslatedTranscript,
                    $onTranslatedAudio,
                    &$sourceTranscript,
                    &$translatedTranscript,
                    &$translatedAudio,
                    &$audibilityBuffer,
                    &$firstSourceTranscriptMs,
                    &$firstTranslatedTranscriptMs,
                    &$firstAudioPacketMs,
                    &$firstAudibleAudioAvailableMs,
                    &$sessionClosedMs,
                ): void {
                    $closed = $this->handleMessage(
                        message: $message,
                        startedAt: $startedAt,
                        onSourceTranscript: $onSourceTranscript,
                        onTranslatedTranscript: $onTranslatedTranscript,
                        onTranslatedAudio: $onTranslatedAudio,
                        sourceTranscript: $sourceTranscript,
                        translatedTranscript: $translatedTranscript,
                        translatedAudio: $translatedAudio,
                        audibilityBuffer: $audibilityBuffer,
                        firstSourceTranscriptMs: $firstSourceTranscriptMs,
                        firstTranslatedTranscriptMs: $firstTranslatedTranscriptMs,
                        firstAudioPacketMs: $firstAudioPacketMs,
                        firstAudibleAudioAvailableMs: $firstAudibleAudioAvailableMs,
                        sessionClosedMs: $sessionClosedMs,
                    );

                    if ($closed) {
                        $connection->stop();
                    }
                },
            );

            $connection->onTick(
                function () use (
                    $connection,
                    $chunks,
                    $targetLanguage,
                    $startedAt,
                    &$sessionConfigured,
                    &$sessionCloseSent,
                    &$audioWasSent,
                    &$nextChunkAt,
                    &$lastChunkEndsAt,
                    &$finalDeadline,
                    &$firstInputAudioSentMs,
                    &$lastInputAudioSentMs,
                    &$inputFinishedMs,
                ): void {
                    if (! $sessionConfigured) {
                        $this->configureSession(
                            connection: $connection,
                            targetLanguage: $targetLanguage,
                        );

                        $sessionConfigured = true;
                        $nextChunkAt = microtime(true);

                        return;
                    }

                    if ($sessionCloseSent) {
                        if (
                            $finalDeadline !== null
                            && microtime(true) >= $finalDeadline
                        ) {
                            throw new RealtimeTranslationException(
                                'Timed out waiting for realtime translation output.',
                            );
                        }

                        return;
                    }

                    $now = microtime(true);

                    if (
                        $nextChunkAt !== null
                        && $now < $nextChunkAt
                    ) {
                        return;
                    }

                    while (
                        $chunks->valid()
                        && $chunks->current() === ''
                    ) {
                        $chunks->next();
                    }

                    if ($chunks->valid()) {
                        $chunk = $chunks->current();

                        $this->validatePcm16Chunk(
                            $chunk,
                        );

                        $sentAtMs =
                            ($now - $startedAt) * 1000;

                        if ($firstInputAudioSentMs === null) {
                            $firstInputAudioSentMs =
                                $sentAtMs;
                        }

                        $this->sendAudioChunk(
                            connection: $connection,
                            chunk: $chunk,
                        );

                        $lastInputAudioSentMs =
                            $sentAtMs;

                        $audioWasSent = true;

                        $chunkDurationSeconds =
                            $this->chunkDurationSeconds(
                                $chunk,
                            );

                        $chunks->next();

                        /*
                         * Advance from the planned schedule rather than from
                         * the actual tick time so event-loop delays do not
                         * accumulate across the stream.
                         */
                        $nextChunkAt +=
                            $chunkDurationSeconds;

                        if (! $chunks->valid()) {
                            $lastChunkEndsAt =
                                $nextChunkAt;
                        }

                        return;
                    }

                    if (! $audioWasSent) {
                        throw new RealtimeTranslationException(
                            'Realtime translation audio stream is empty.',
                        );
                    }

                    if (
                        $lastChunkEndsAt !== null
                        && $now < $lastChunkEndsAt
                    ) {
                        return;
                    }

                    $inputFinishedMs =
                        ($now - $startedAt) * 1000;

                    $connection->sendText(
                        json_encode(
                            [
                                'type' => 'session.close',
                            ],
                            JSON_THROW_ON_ERROR,
                        ),
                    );

                    $sessionCloseSent = true;

                    $finalDeadline =
                        $now
                        + self::FINAL_OUTPUT_TIMEOUT_SECONDS;
                },
            );

            $connection->start(
                self::EVENT_LOOP_TIMEOUT_SECONDS,
            );

            if ($firstInputAudioSentMs === null) {
                throw new RealtimeTranslationException(
                    'Realtime translation stream ended before input audio was sent.',
                );
            }

            if ($lastInputAudioSentMs === null) {
                throw new RealtimeTranslationException(
                    'Realtime translation stream ended without a final input audio chunk.',
                );
            }

            if ($inputFinishedMs === null) {
                throw new RealtimeTranslationException(
                    'Realtime translation stream ended before input completed.',
                );
            }

            if ($sessionClosedMs === null) {
                throw new RealtimeTranslationException(
                    'Realtime translation session ended without session.closed.',
                );
            }

            if (trim($translatedTranscript) === '') {
                throw new RealtimeTranslationException(
                    'Realtime translation returned no translated transcript.',
                );
            }

            if ($translatedAudio === '') {
                throw new RealtimeTranslationException(
                    'Realtime translation returned no translated audio.',
                );
            }

            $leadingSilenceMs =
                $this->firstAudibleAudioOffsetMilliseconds(
                    $translatedAudio,
                );

            $rawPlaybackFirstAudibleAudioMs =
                $firstAudioPacketMs !== null
                && $leadingSilenceMs !== null
                    ? $firstAudioPacketMs
                        + $leadingSilenceMs
                    : null;

            return new RealtimeTranslationResult(
                sourceTranscript: trim($sourceTranscript) !== ''
                    ? trim($sourceTranscript)
                    : null,

                translatedTranscript: trim($translatedTranscript),

                translatedAudio: $translatedAudio,

                firstSourceTranscriptMs: $firstSourceTranscriptMs,

                firstTranslatedTranscriptMs: $firstTranslatedTranscriptMs,

                firstInputAudioSentMs: $firstInputAudioSentMs,

                lastInputAudioSentMs: $lastInputAudioSentMs,

                inputFinishedMs: $inputFinishedMs,

                firstAudioPacketMs: $firstAudioPacketMs,

                leadingSilenceMs: $leadingSilenceMs,

                rawPlaybackFirstAudibleAudioMs: $rawPlaybackFirstAudibleAudioMs,

                firstAudibleAudioAvailableMs: $firstAudibleAudioAvailableMs,

                sessionClosedMs: $sessionClosedMs,

                firstAudioPacketRelativeToInputEndMs: $firstAudioPacketMs === null
                        ? null
                        : $firstAudioPacketMs
                            - $inputFinishedMs,

                rawPlaybackFirstAudibleAudioRelativeToInputEndMs: $rawPlaybackFirstAudibleAudioMs === null
                        ? null
                        : $rawPlaybackFirstAudibleAudioMs
                            - $inputFinishedMs,

                firstAudibleAudioAvailableRelativeToInputEndMs: $firstAudibleAudioAvailableMs === null
                        ? null
                        : $firstAudibleAudioAvailableMs
                            - $inputFinishedMs,

                firstAudibleAudioAvailableRelativeToFirstInputMs: $firstAudibleAudioAvailableMs === null
                        ? null
                        : $firstAudibleAudioAvailableMs
                            - $firstInputAudioSentMs,

                firstAudibleAudioAvailableRelativeToLastInputMs: $firstAudibleAudioAvailableMs === null
                        ? null
                        : $firstAudibleAudioAvailableMs
                            - $lastInputAudioSentMs,

                inputAudioStreamDurationMs: $inputFinishedMs
                    - $firstInputAudioSentMs,

                translatedAudioDurationMs: $this->audioDurationMilliseconds(
                    $translatedAudio,
                ),
            );
        } catch (RealtimeTranslationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RealtimeTranslationException(
                'Realtime translation provider failed.',
                previous: $exception,
            );
        } finally {
            if (
                $connection
                instanceof StreamingWebSocketConnection
            ) {
                try {
                    $connection->close();
                } catch (Throwable) {
                    //
                }
            }
        }
    }

    private function buildUrl(): string
    {
        return rtrim(
            $this->endpoint,
            '?',
        ).'?'.http_build_query(
            [
                'model' => $this->model,
            ],
            encoding_type: PHP_QUERY_RFC3986,
        );
    }

    private function configureSession(
        StreamingWebSocketConnection $connection,
        Language $targetLanguage,
    ): void {
        $connection->sendText(
            json_encode(
                [
                    'type' => 'session.update',
                    'session' => [
                        'audio' => [
                            'output' => [
                                'language' => $this->languageCode(
                                    $targetLanguage,
                                ),
                            ],
                        ],
                    ],
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function sendAudioChunk(
        StreamingWebSocketConnection $connection,
        string $chunk,
    ): void {
        $connection->sendText(
            json_encode(
                [
                    'type' => 'session.input_audio_buffer.append',
                    'audio' => base64_encode($chunk),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function handleMessage(
        string $message,
        float $startedAt,
        ?Closure $onSourceTranscript,
        ?Closure $onTranslatedTranscript,
        ?Closure $onTranslatedAudio,
        string &$sourceTranscript,
        string &$translatedTranscript,
        string &$translatedAudio,
        string &$audibilityBuffer,
        ?float &$firstSourceTranscriptMs,
        ?float &$firstTranslatedTranscriptMs,
        ?float &$firstAudioPacketMs,
        ?float &$firstAudibleAudioAvailableMs,
        ?float &$sessionClosedMs,
    ): bool {
        try {
            $payload = json_decode(
                $message,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RealtimeTranslationException(
                'Realtime translation provider returned invalid JSON.',
                previous: $exception,
            );
        }

        if (! is_array($payload)) {
            return false;
        }

        $type = $payload['type'] ?? null;

        if ($type === 'error') {
            $error = $payload['error'] ?? null;

            $code = is_array($error)
                ? ($error['code'] ?? 'UNKNOWN_ERROR')
                : 'UNKNOWN_ERROR';

            $description = is_array($error)
                ? ($error['message'] ?? 'Unknown OpenAI error.')
                : 'Unknown OpenAI error.';

            throw new RealtimeTranslationException(
                sprintf(
                    'OpenAI Realtime Translation error [%s]: %s',
                    is_scalar($code)
                        ? (string) $code
                        : 'UNKNOWN_ERROR',
                    is_scalar($description)
                        ? (string) $description
                        : 'Unknown OpenAI error.',
                ),
            );
        }

        if ($type === 'session.input_transcript.delta') {
            $delta = $payload['delta'] ?? null;

            if (! is_string($delta) || $delta === '') {
                return false;
            }

            if ($firstSourceTranscriptMs === null) {
                $firstSourceTranscriptMs =
                    $this->elapsedMilliseconds(
                        $startedAt,
                    );
            }

            $sourceTranscript .= $delta;

            if ($onSourceTranscript !== null) {
                $onSourceTranscript(
                    $delta,
                );
            }

            return false;
        }

        if ($type === 'session.output_transcript.delta') {
            $delta = $payload['delta'] ?? null;

            if (! is_string($delta) || $delta === '') {
                return false;
            }

            if ($firstTranslatedTranscriptMs === null) {
                $firstTranslatedTranscriptMs =
                    $this->elapsedMilliseconds(
                        $startedAt,
                    );
            }

            $translatedTranscript .= $delta;

            if ($onTranslatedTranscript !== null) {
                $onTranslatedTranscript(
                    $delta,
                );
            }

            return false;
        }

        if ($type === 'session.output_audio.delta') {
            $delta = $payload['delta'] ?? null;

            if (! is_string($delta) || $delta === '') {
                return false;
            }

            $audio = base64_decode(
                $delta,
                true,
            );

            if ($audio === false) {
                throw new RealtimeTranslationException(
                    'Realtime translation provider returned invalid audio data.',
                );
            }

            $receivedAtMs =
                $this->elapsedMilliseconds(
                    $startedAt,
                );

            if ($firstAudioPacketMs === null) {
                $firstAudioPacketMs =
                    $receivedAtMs;
            }

            if ($firstAudibleAudioAvailableMs === null) {
                $audibilityBuffer .= $audio;

                if (
                    $this->consumeAudibilityBufferUntilAudible(
                        $audibilityBuffer,
                    )
                ) {
                    /*
                     * This is wall-clock arrival time.
                     *
                     * At this moment the client already owns a complete
                     * non-silent PCM frame and could buffer/play it.
                     */
                    $firstAudibleAudioAvailableMs =
                        $receivedAtMs;
                }
            }

            $translatedAudio .= $audio;

            if ($onTranslatedAudio !== null) {
                $onTranslatedAudio(
                    $audio,
                );
            }

            return false;
        }

        if ($type === 'session.closed') {
            $sessionClosedMs =
                $this->elapsedMilliseconds(
                    $startedAt,
                );

            return true;
        }

        return false;
    }

    private function consumeAudibilityBufferUntilAudible(
        string &$buffer,
    ): bool {
        $frameBytes =
            $this->audibleFrameBytes();

        $bufferLength =
            strlen($buffer);

        $processedBytes = 0;

        while (
            $bufferLength
            - $processedBytes
            >= $frameBytes
        ) {
            $frame = substr(
                $buffer,
                $processedBytes,
                $frameBytes,
            );

            if (
                $this->pcmRms($frame)
                >= self::AUDIBLE_RMS_THRESHOLD
            ) {
                $buffer = '';

                return true;
            }

            $processedBytes +=
                $frameBytes;
        }

        if ($processedBytes > 0) {
            $buffer = substr(
                $buffer,
                $processedBytes,
            );
        }

        return false;
    }

    private function firstAudibleAudioOffsetMilliseconds(
        string $audio,
    ): ?float {
        $frameBytes =
            $this->audibleFrameBytes();

        $length =
            strlen($audio);

        for (
            $byteOffset = 0;
            $byteOffset + $frameBytes <= $length;
            $byteOffset += $frameBytes
        ) {
            $frame = substr(
                $audio,
                $byteOffset,
                $frameBytes,
            );

            if (
                $this->pcmRms($frame)
                >= self::AUDIBLE_RMS_THRESHOLD
            ) {
                return (
                    $byteOffset
                    / (
                        self::PCM_SAMPLE_RATE
                        * self::PCM_BYTES_PER_SAMPLE
                    )
                ) * 1000;
            }
        }

        return null;
    }

    private function pcmRms(
        string $pcm,
    ): float {
        $sampleCount = intdiv(
            strlen($pcm),
            self::PCM_BYTES_PER_SAMPLE,
        );

        if ($sampleCount === 0) {
            return 0.0;
        }

        $sumSquares = 0.0;

        for (
            $sampleIndex = 0;
            $sampleIndex < $sampleCount;
            $sampleIndex++
        ) {
            $byteOffset =
                $sampleIndex
                * self::PCM_BYTES_PER_SAMPLE;

            $value =
                ord($pcm[$byteOffset])
                | (
                    ord($pcm[$byteOffset + 1])
                    << 8
                );

            if ($value >= 0x8000) {
                $value -= 0x10000;
            }

            $sumSquares +=
                $value * $value;
        }

        return sqrt(
            $sumSquares
            / $sampleCount,
        );
    }

    private function audibleFrameBytes(): int
    {
        $frameSamples = (int) (
            self::PCM_SAMPLE_RATE
            * self::AUDIBLE_FRAME_DURATION_MS
            / 1000
        );

        return $frameSamples
            * self::PCM_BYTES_PER_SAMPLE;
    }

    private function audioDurationMilliseconds(
        string $audio,
    ): float {
        return (
            strlen($audio)
            / (
                self::PCM_SAMPLE_RATE
                * self::PCM_BYTES_PER_SAMPLE
            )
        ) * 1000;
    }

    private function validatePcm16Chunk(
        string $chunk,
    ): void {
        if (
            strlen($chunk)
            % self::PCM_BYTES_PER_SAMPLE !== 0
        ) {
            throw new RealtimeTranslationException(
                'PCM16 audio chunk must contain a whole number of samples.',
            );
        }
    }

    private function chunkDurationSeconds(
        string $chunk,
    ): float {
        return strlen($chunk)
            / (
                self::PCM_SAMPLE_RATE
                * self::PCM_BYTES_PER_SAMPLE
            );
    }

    private function elapsedMilliseconds(
        float $startedAt,
    ): float {
        return (
            microtime(true)
            - $startedAt
        ) * 1000;
    }

    /**
     * @param  iterable<int, string>  $audioChunks
     * @return Generator<int, string>
     */
    private function chunkIterator(
        iterable $audioChunks,
    ): Generator {
        foreach ($audioChunks as $chunk) {
            yield $chunk;
        }
    }

    private function languageCode(
        Language $language,
    ): string {
        return match ($language) {
            Language::English => 'en',
            Language::Russian => 'ru',
        };
    }
}
