<?php

use App\Contracts\StreamingWebSocketConnection;
use App\Contracts\StreamingWebSocketFactory;
use App\DTO\StreamingTranscriptionResult;
use App\Enums\Language;
use App\Exceptions\SpeechRecognitionException;
use App\Services\Speech\DeepgramFluxSpeechToTextProvider;
use Closure;

class FakeFluxWebSocketConnection implements StreamingWebSocketConnection
{
    /**
     * @param  array<int, string>  $messages
     */
    public function __construct(
        private array $messages = [],
    ) {}

    /**
     * @var array<int, string>
     */
    public array $binaryMessages = [];

    /**
     * @var array<int, string>
     */
    public array $textMessages = [];

    public bool $closed = false;

    public bool $stopped = false;

    public ?float $startTimeout = null;

    private ?Closure $textListener = null;

    private ?Closure $tickListener = null;

    public function sendBinary(
        string $payload,
    ): void {
        $this->binaryMessages[] =
            $payload;
    }

    public function sendText(
        string $payload,
    ): void {
        $this->textMessages[] =
            $payload;
    }

    public function onText(
        Closure $listener,
    ): void {
        $this->textListener =
            $listener;
    }

    public function onTick(
        Closure $listener,
    ): void {
        $this->tickListener =
            $listener;
    }

    public function start(
        float $timeoutSeconds,
    ): void {
        $this->startTimeout =
            $timeoutSeconds;

        for (
            $iteration = 0;
            $iteration < 100
            && ! $this->stopped;
            $iteration++
        ) {
            if ($this->tickListener !== null) {
                ($this->tickListener)();
            }

            if (
                $this->messages === []
                || $this->textListener === null
            ) {
                continue;
            }

            $message = $this->messages[0];

            if (
                $this->isEndOfTurn($message)
                && ! $this->forceEndTurnWasSent()
            ) {
                continue;
            }

            array_shift(
                $this->messages,
            );

            ($this->textListener)(
                $message,
            );
        }
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    private function forceEndTurnWasSent(): bool
    {
        foreach ($this->textMessages as $message) {
            $payload = json_decode(
                $message,
                true,
            );

            if (
                is_array($payload)
                && ($payload['type'] ?? null)
                    === 'ForceEndTurn'
            ) {
                return true;
            }
        }

        return false;
    }

    private function isEndOfTurn(
        string $message,
    ): bool {
        $payload = json_decode(
            $message,
            true,
        );

        return is_array($payload)
            && ($payload['type'] ?? null)
                === 'TurnInfo'
            && ($payload['event'] ?? null)
                === 'EndOfTurn';
    }
}

class FakeFluxWebSocketFactory implements StreamingWebSocketFactory
{
    public ?string $url = null;

    /**
     * @var array<string, string>
     */
    public array $headers = [];

    public ?int $timeoutSeconds = null;

    public function __construct(
        public FakeFluxWebSocketConnection $connection,
    ) {}

    public function connect(
        string $url,
        array $headers = [],
        int $timeoutSeconds = 30,
    ): StreamingWebSocketConnection {
        $this->url = $url;
        $this->headers = $headers;
        $this->timeoutSeconds =
            $timeoutSeconds;

        return $this->connection;
    }
}

it(
    'streams audio to Deepgram Flux and returns the final transcript',
    function () {
        $connection =
            new FakeFluxWebSocketConnection([
                json_encode(
                    [
                        'type' => 'TurnInfo',
                        'event' => 'Update',
                        'transcript' => 'hello',
                    ],
                    JSON_THROW_ON_ERROR,
                ),

                json_encode(
                    [
                        'type' => 'TurnInfo',
                        'event' => 'EndOfTurn',
                        'transcript' => 'hello world',
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ]);

        $factory =
            new FakeFluxWebSocketFactory(
                connection: $connection,
            );

        $provider =
            new DeepgramFluxSpeechToTextProvider(
                webSocketFactory: $factory,
                apiKey: 'test-api-key',
                endpoint: 'wss://api.deepgram.com/v2/listen',
                model: 'flux-general-multi',
            );

        $events = [];

        $result = $provider->transcribe(
            audioChunks: [
                'first-chunk',
                'second-chunk',
            ],
            mimeType: 'audio/webm',
            sourceLanguage: Language::English,
            onTranscript: function (
                string $text,
                bool $isFinal,
            ) use (&$events): void {
                $events[] = [
                    'text' => $text,
                    'is_final' => $isFinal,
                ];
            },
        );

        expect($result)
            ->toBeInstanceOf(
                StreamingTranscriptionResult::class,
            )
            ->and($result->text)
            ->toBe('hello world');

        expect($factory->url)
            ->not->toBeNull();

        parse_str(
            parse_url(
                $factory->url,
                PHP_URL_QUERY,
            ),
            $query,
        );

        expect($query)
            ->toMatchArray([
                'model' => 'flux-general-multi',
                'language_hint' => 'en',
                'eot_threshold' => '1.0',
                'eot_timeout_ms' => '60000',
            ]);

        expect($factory->headers)
            ->toMatchArray([
                'Authorization' => 'Token test-api-key',
            ]);

        expect(
            $connection->binaryMessages
        )->toBe([
            'first-chunk',
            'second-chunk',
        ]);

        expect($events)
            ->toBe([
                [
                    'text' => 'hello',
                    'is_final' => false,
                ],
                [
                    'text' => 'hello world',
                    'is_final' => true,
                ],
            ]);

        expect(
            $connection->textMessages
        )->toHaveCount(2);

        expect(
            json_decode(
                $connection->textMessages[0],
                true,
                flags: JSON_THROW_ON_ERROR,
            )
        )->toBe([
            'type' => 'ForceEndTurn',
        ]);

        expect(
            json_decode(
                $connection->textMessages[1],
                true,
                flags: JSON_THROW_ON_ERROR,
            )
        )->toBe([
            'type' => 'CloseStream',
        ]);

        expect(
            $connection->startTimeout
        )->toBe(0.005);

        expect($connection->stopped)
            ->toBeTrue();

        expect($connection->closed)
            ->toBeTrue();
    }
);

it(
    'uses Russian language hint for Russian audio',
    function () {
        $connection =
            new FakeFluxWebSocketConnection([
                json_encode(
                    [
                        'type' => 'TurnInfo',
                        'event' => 'EndOfTurn',
                        'transcript' => 'привет мир',
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ]);

        $factory =
            new FakeFluxWebSocketFactory(
                connection: $connection,
            );

        $provider =
            new DeepgramFluxSpeechToTextProvider(
                webSocketFactory: $factory,
                apiKey: 'test-api-key',
                endpoint: 'wss://api.deepgram.com/v2/listen',
                model: 'flux-general-multi',
            );

        $provider->transcribe(
            audioChunks: [
                'audio',
            ],
            mimeType: 'audio/webm',
            sourceLanguage: Language::Russian,
            onTranscript: static function (
                string $text,
                bool $isFinal,
            ): void {
                //
            },
        );

        expect($factory->url)
            ->not->toBeNull();

        parse_str(
            parse_url(
                $factory->url,
                PHP_URL_QUERY,
            ),
            $query,
        );

        expect(
            $query['language_hint'] ?? null
        )->toBe('ru');
    }
);

it(
    'converts Deepgram Flux error events into speech recognition exceptions',
    function () {
        $connection =
            new FakeFluxWebSocketConnection([
                json_encode(
                    [
                        'type' => 'Error',
                        'code' => 'INVALID_REQUEST',
                        'description' => 'Something went wrong.',
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ]);

        $factory =
            new FakeFluxWebSocketFactory(
                connection: $connection,
            );

        $provider =
            new DeepgramFluxSpeechToTextProvider(
                webSocketFactory: $factory,
                apiKey: 'test-api-key',
                endpoint: 'wss://api.deepgram.com/v2/listen',
                model: 'flux-general-multi',
            );

        expect(
            fn () => $provider->transcribe(
                audioChunks: [
                    'audio',
                ],
                mimeType: 'audio/webm',
                sourceLanguage: Language::English,
                onTranscript: static function (
                    string $text,
                    bool $isFinal,
                ): void {
                    //
                },
            )
        )->toThrow(
            SpeechRecognitionException::class,
            'Deepgram Flux error [INVALID_REQUEST]: Something went wrong.',
        );

        expect($connection->closed)
            ->toBeTrue();
    }
);

it(
    'rejects invalid JSON returned by Deepgram Flux',
    function () {
        $connection =
            new FakeFluxWebSocketConnection([
                '{invalid-json',
            ]);

        $factory =
            new FakeFluxWebSocketFactory(
                connection: $connection,
            );

        $provider =
            new DeepgramFluxSpeechToTextProvider(
                webSocketFactory: $factory,
                apiKey: 'test-api-key',
                endpoint: 'wss://api.deepgram.com/v2/listen',
                model: 'flux-general-multi',
            );

        expect(
            fn () => $provider->transcribe(
                audioChunks: [
                    'audio',
                ],
                mimeType: 'audio/webm',
                sourceLanguage: Language::English,
                onTranscript: static function (
                    string $text,
                    bool $isFinal,
                ): void {
                    //
                },
            )
        )->toThrow(
            SpeechRecognitionException::class,
            'Speech recognition provider returned invalid JSON.',
        );

        expect($connection->closed)
            ->toBeTrue();
    }
);
