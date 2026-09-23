<?php

namespace App\Services\Speech;

use App\Contracts\StreamingSpeechToTextProvider;
use App\Contracts\StreamingWebSocketConnection;
use App\Contracts\StreamingWebSocketFactory;
use App\DTO\StreamingTranscriptionResult;
use App\Enums\Language;
use App\Exceptions\SpeechRecognitionException;
use Closure;
use Generator;
use JsonException;
use Throwable;

final readonly class DeepgramFluxSpeechToTextProvider implements StreamingSpeechToTextProvider
{
    private const EVENT_LOOP_TIMEOUT_SECONDS = 0.005;

    private const FINAL_TRANSCRIPT_TIMEOUT_SECONDS = 10.0;

    /**
     * @param  list<string>  $keyterms
     */
    public function __construct(
        private StreamingWebSocketFactory $webSocketFactory,
        private string $apiKey,
        private string $endpoint,
        private string $model = 'flux-general-multi',
        private array $keyterms = [],
    ) {}

    public function transcribe(
        iterable $audioChunks,
        string $mimeType,
        Language $sourceLanguage,
        Closure $onTranscript,
    ): StreamingTranscriptionResult {
        $connection = null;

        try {
            $connection = $this->webSocketFactory->connect(
                url: $this->buildUrl(
                    sourceLanguage: $sourceLanguage,
                ),
                headers: [
                    'Authorization' => "Token {$this->apiKey}",
                ],
                timeoutSeconds: 30,
            );

            $chunks = $this->chunkIterator(
                $audioChunks,
            );

            $iteratorStarted = false;
            $forceEndTurnSent = false;
            $finalDeadline = null;
            $finalTranscript = '';

            $connection->onText(
                function (
                    string $message,
                ) use (
                    $connection,
                    $onTranscript,
                    &$finalTranscript,
                ): void {
                    $isFinal = $this->handleMessage(
                        message: $message,
                        onTranscript: $onTranscript,
                        finalTranscript: $finalTranscript,
                    );

                    if (! $isFinal) {
                        return;
                    }

                    $this->closeStream(
                        $connection,
                    );

                    $connection->stop();
                },
            );

            $connection->onTick(
                function () use (
                    $connection,
                    $chunks,
                    &$iteratorStarted,
                    &$forceEndTurnSent,
                    &$finalDeadline,
                ): void {
                    if ($forceEndTurnSent) {
                        if (
                            $finalDeadline !== null
                            && microtime(true) >= $finalDeadline
                        ) {
                            throw new SpeechRecognitionException(
                                'Timed out waiting for final speech transcript.'
                            );
                        }

                        return;
                    }

                    if (! $iteratorStarted) {
                        $chunks->rewind();
                        $iteratorStarted = true;
                    } else {
                        $chunks->next();
                    }

                    if ($chunks->valid()) {
                        $chunk = $chunks->current();

                        if ($chunk !== '') {
                            $connection->sendBinary(
                                $chunk,
                            );
                        }

                        return;
                    }

                    $connection->sendText(
                        json_encode(
                            [
                                'type' => 'ForceEndTurn',
                            ],
                            JSON_THROW_ON_ERROR,
                        ),
                    );

                    $forceEndTurnSent = true;

                    $finalDeadline =
                        microtime(true)
                        + self::FINAL_TRANSCRIPT_TIMEOUT_SECONDS;
                },
            );

            $connection->start(
                self::EVENT_LOOP_TIMEOUT_SECONDS,
            );

            if ($finalTranscript === '') {
                throw new SpeechRecognitionException(
                    'Speech recognition stream ended before final transcript.'
                );
            }

            return new StreamingTranscriptionResult(
                text: $finalTranscript,
            );
        } catch (SpeechRecognitionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SpeechRecognitionException(
                'Speech recognition provider failed.',
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

    private function buildUrl(
        Language $sourceLanguage,
    ): string {
        $query = http_build_query(
            [
                'model' => $this->model,
                'language_hint' => $this->languageCode(
                    $sourceLanguage,
                ),
                'eot_threshold' => '1.0',
                'eot_timeout_ms' => '60000',
            ],
            encoding_type: PHP_QUERY_RFC3986,
        );

        foreach ($this->keyterms as $keyterm) {
            $keyterm = trim($keyterm);

            if ($keyterm === '') {
                continue;
            }

            $query .= '&keyterm='
                .rawurlencode($keyterm);
        }

        return rtrim(
            $this->endpoint,
            '?',
        ).'?'.$query;
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

    private function handleMessage(
        string $message,
        Closure $onTranscript,
        string &$finalTranscript,
    ): bool {
        try {
            $payload = json_decode(
                $message,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new SpeechRecognitionException(
                'Speech recognition provider returned invalid JSON.',
                previous: $exception,
            );
        }

        if (! is_array($payload)) {
            return false;
        }

        $type = $payload['type'] ?? null;

        if ($type === 'Error') {
            $code = $payload['code']
                ?? 'UNKNOWN_ERROR';

            $description =
                $payload['description']
                ?? 'Unknown Deepgram error.';

            throw new SpeechRecognitionException(
                sprintf(
                    'Deepgram Flux error [%s]: %s',
                    is_scalar($code)
                        ? (string) $code
                        : 'UNKNOWN_ERROR',
                    is_scalar($description)
                        ? (string) $description
                        : 'Unknown Deepgram error.',
                ),
            );
        }

        if ($type !== 'TurnInfo') {
            return false;
        }

        $event = $payload['event'] ?? null;
        $transcript =
            $payload['transcript'] ?? null;

        if (
            ! is_string($transcript)
            || trim($transcript) === ''
        ) {
            return false;
        }

        $transcript = trim(
            $transcript,
        );

        $isFinal =
            $event === 'EndOfTurn';

        $onTranscript(
            $transcript,
            $isFinal,
        );

        if ($isFinal) {
            $finalTranscript =
                $transcript;
        }

        return $isFinal;
    }

    private function closeStream(
        StreamingWebSocketConnection $connection,
    ): void {
        try {
            $connection->sendText(
                json_encode(
                    [
                        'type' => 'CloseStream',
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            );
        } catch (Throwable) {
            //
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
