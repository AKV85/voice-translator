<?php

namespace App\Console\Commands;

use App\Contracts\StreamingSpeechToTextProvider;
use App\Enums\Language;
use App\Services\Live\LiveSpeechRecognitionSession;
use Google\Cloud\Speech\V2\Client\SpeechClient;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use LogicException;
use RuntimeException;
use Throwable;
use WebSocket\Connection;
use WebSocket\Exception\ExceptionInterface;
use WebSocket\Message\Binary;
use WebSocket\Message\Text;
use WebSocket\Server;

final class ServeLivePipelineCommand extends Command
{
    private const PROFILE =
        'chirp3-streaming-standard-deepl-openai';

    protected $signature =
        'live-pipeline:serve
        {--port=8081 : WebSocket server port}';

    protected $description =
        'Serve the live voice translation WebSocket pipeline';

    public function handle(): int
    {
        $port = (int) $this->option('port');

        if ($port < 1 || $port > 65535) {
            $this->error(
                'WebSocket port must be between 1 and 65535.',
            );

            return self::FAILURE;
        }

        $speechToText =
            $this->resolveSpeechToTextProvider();

        /**
         * @var array<int, LiveSpeechRecognitionSession> $sessions
         */
        $sessions = [];

        $server = new Server(
            port: $port,
        );

        $server->onText(
            function (
                Server $server,
                Connection $connection,
                Text $message,
            ) use (
                $speechToText,
                &$sessions,
            ): void {
                try {
                    $this->handleTextMessage(
                        connection: $connection,
                        message: $message,
                        speechToText: $speechToText,
                        sessions: $sessions,
                    );
                } catch (Throwable $exception) {
                    unset(
                        $sessions[
                            spl_object_id($connection)
                        ],
                    );

                    $this->sendError(
                        connection: $connection,
                        message: $exception->getMessage(),
                    );
                }
            },
        );

        $server->onBinary(
            function (
                Server $server,
                Connection $connection,
                Binary $message,
            ) use (&$sessions): void {
                try {
                    $this->handleBinaryMessage(
                        connection: $connection,
                        message: $message,
                        sessions: $sessions,
                    );
                } catch (Throwable $exception) {
                    unset(
                        $sessions[
                            spl_object_id($connection)
                        ],
                    );

                    $this->sendError(
                        connection: $connection,
                        message: $exception->getMessage(),
                    );
                }
            },
        );

        $server->onDisconnect(
            function (
                Server $server,
                Connection $connection,
            ) use (&$sessions): void {
                unset(
                    $sessions[
                        spl_object_id($connection)
                    ],
                );
            },
        );

        $server->onError(
            function (
                Server $server,
                ?Connection $connection,
                ExceptionInterface $exception,
            ): void {
                $this->error(
                    $exception->getMessage(),
                );
            },
        );

        $this->info(
            sprintf(
                'Live pipeline WebSocket server listening on port %d.',
                $port,
            ),
        );

        $this->line(
            'Profile: '.self::PROFILE,
        );

        $server->start(
            0.005,
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<int, LiveSpeechRecognitionSession>  $sessions
     *
     * @throws JsonException
     */
    private function handleTextMessage(
        Connection $connection,
        Text $message,
        StreamingSpeechToTextProvider $speechToText,
        array &$sessions,
    ): void {
        $payload = json_decode(
            $message->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($payload)) {
            throw new InvalidArgumentException(
                'Control message must be a JSON object.',
            );
        }

        $type = $payload['type']
            ?? null;

        if ($type === 'start') {
            $this->startSession(
                connection: $connection,
                payload: $payload,
                speechToText: $speechToText,
                sessions: $sessions,
            );

            return;
        }

        if ($type === 'stop') {
            $this->finishSession(
                connection: $connection,
                sessions: $sessions,
            );

            return;
        }

        throw new InvalidArgumentException(
            'Unsupported live pipeline control message.',
        );
    }

    /**
     * @param  array<int, LiveSpeechRecognitionSession>  $sessions
     */
    private function handleBinaryMessage(
        Connection $connection,
        Binary $message,
        array &$sessions,
    ): void {
        $key = spl_object_id(
            $connection,
        );

        $session = $sessions[$key]
            ?? null;

        if ($session === null) {
            throw new LogicException(
                'No active live speech recognition session.',
            );
        }

        $session->pushAudio(
            $message->getContent(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, LiveSpeechRecognitionSession>  $sessions
     */
    private function startSession(
        Connection $connection,
        array $payload,
        StreamingSpeechToTextProvider $speechToText,
        array &$sessions,
    ): void {
        $key = spl_object_id(
            $connection,
        );

        if (
            isset($sessions[$key])
            && $sessions[$key]->isRunning()
        ) {
            throw new LogicException(
                'A live speech recognition session is already active.',
            );
        }

        $profile = $payload['profile']
            ?? self::PROFILE;

        if ($profile !== self::PROFILE) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported live pipeline profile [%s].',
                    is_scalar($profile)
                        ? (string) $profile
                        : 'invalid',
                ),
            );
        }

        $sourceLanguageValue =
            $payload['source_language']
            ?? null;

        if (! is_string($sourceLanguageValue)) {
            throw new InvalidArgumentException(
                'Source language is required.',
            );
        }

        $sourceLanguage =
            Language::tryFrom(
                $sourceLanguageValue,
            );

        if ($sourceLanguage === null) {
            throw new InvalidArgumentException(
                'Unsupported source language.',
            );
        }

        $mimeType = $payload['mime_type']
            ?? null;

        if (
            ! is_string($mimeType)
            || trim($mimeType) === ''
        ) {
            throw new InvalidArgumentException(
                'Audio MIME type is required.',
            );
        }

        $session =
            new LiveSpeechRecognitionSession(
                speechToText: $speechToText,
                mimeType: $mimeType,
                sourceLanguage: $sourceLanguage,
                onTranscript: function (
                    string $text,
                    bool $isFinal,
                ) use ($connection): void {
                    $this->sendJson(
                        $connection,
                        [
                            'type' => 'transcript',
                            'text' => $text,
                            'is_final' => $isFinal,
                        ],
                    );
                },
            );

        $session->start();

        $sessions[$key] =
            $session;

        $this->sendJson(
            $connection,
            [
                'type' => 'session_started',
                'profile' => self::PROFILE,
                'source_language' => $sourceLanguage->value,
                'mime_type' => $mimeType,
            ],
        );
    }

    /**
     * @param  array<int, LiveSpeechRecognitionSession>  $sessions
     */
    private function finishSession(
        Connection $connection,
        array &$sessions,
    ): void {
        $key = spl_object_id(
            $connection,
        );

        $session = $sessions[$key]
            ?? null;

        if ($session === null) {
            throw new LogicException(
                'No active live speech recognition session.',
            );
        }

        $stoppedAt =
            hrtime(true);

        $result =
            $session->finish();

        $stopToSttMs =
            (
                hrtime(true)
                - $stoppedAt
            ) / 1_000_000;

        unset(
            $sessions[$key],
        );

        $this->sendJson(
            $connection,
            [
                'type' => 'completed',
                'text' => $result->text,
                'server_stop_to_stt_ms' => round(
                    $stopToSttMs,
                    2,
                ),
            ],
        );
    }

    private function resolveSpeechToTextProvider(): StreamingSpeechToTextProvider
    {
        $profile = config(
            'benchmarks.translation.pipeline.providers.'
            .self::PROFILE
            .'.speech_to_text',
        );

        if (! is_array($profile)) {
            throw new RuntimeException(
                'Live pipeline speech profile is not configured.',
            );
        }

        $contract = $profile['contract']
            ?? null;

        $profileConfig = $profile['config']
            ?? null;

        if (
            ! is_string($contract)
            || ! is_array($profileConfig)
        ) {
            throw new RuntimeException(
                'Live pipeline speech profile is invalid.',
            );
        }

        config(
            $profileConfig,
        );

        app()->forgetInstance(
            SpeechClient::class,
        );

        $provider =
            app($contract);

        if (
            ! $provider
                instanceof StreamingSpeechToTextProvider
        ) {
            throw new RuntimeException(
                'Live pipeline speech provider must implement '
                .StreamingSpeechToTextProvider::class
                .'.',
            );
        }

        return $provider;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    private function sendJson(
        Connection $connection,
        array $payload,
    ): void {
        $connection->text(
            json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function sendError(
        Connection $connection,
        string $message,
    ): void {
        try {
            $this->sendJson(
                $connection,
                [
                    'type' => 'error',
                    'message' => $message,
                ],
            );
        } catch (Throwable) {
            //
        }
    }
}
