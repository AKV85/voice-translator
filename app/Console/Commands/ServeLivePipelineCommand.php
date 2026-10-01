<?php

namespace App\Console\Commands;

use App\Contracts\StreamingSpeechToTextProvider;
use App\Contracts\StreamingTextToSpeechProvider;
use App\Contracts\TranslationProvider;
use App\Enums\Language;
use App\Exceptions\PublicDemoException;
use App\Services\Live\LiveSpeechRecognitionSession;
use App\Services\PublicDemo\PublicDemoTokenStore;
use App\Services\PublicDemo\PublicDemoWebSocketSession;
use Google\Cloud\Speech\V2\Client\SpeechClient;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use LogicException;
use RuntimeException;
use Throwable;
use WebSocket\Connection;
use WebSocket\Exception\ConnectionClosedException;
use WebSocket\Exception\ExceptionInterface;
use WebSocket\Message\Binary;
use WebSocket\Message\Text;
use WebSocket\Server;

final class ServeLivePipelineCommand extends Command
{
    private const DEFAULT_PROFILE =
        'chirp3-streaming-standard-deepl-openai';

    private const LIVE_PROFILES = [
        'chirp3-deepl-openai',
        'chirp3-streaming-standard-deepl-openai',
        'chirp3-streaming-short-deepl-openai',
        'flux-deepl-openai',
    ];

    private const TTS_SAMPLE_RATE = 24000;

    private const TTS_CHANNELS = 1;

    private const TTS_BYTES_PER_SAMPLE = 2;

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

        /**
         * @var array<int, array{
         *     session: LiveSpeechRecognitionSession,
         *     profile: string,
         *     source_language: Language,
         *     translation: TranslationProvider,
         *     text_to_speech: StreamingTextToSpeechProvider,
         *     public_demo: PublicDemoWebSocketSession|null
         * }> $sessions
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
            ) use (&$sessions): void {
                try {
                    $this->handleTextMessage(
                        connection: $connection,
                        message: $message,
                        sessions: $sessions,
                    );
                } catch (Throwable $exception) {
                    $isPublicDemo =
                        $this->cleanupSession(
                            connection: $connection,
                            sessions: $sessions,
                        );

                    report($exception);

                    $this->sendError(
                        connection: $connection,
                        message: (
                            $isPublicDemo
                            || $exception instanceof PublicDemoException
                        )
                            ? 'Public voice demo request failed.'
                            : $exception->getMessage(),
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
                    $isPublicDemo =
                        $this->cleanupSession(
                            connection: $connection,
                            sessions: $sessions,
                        );

                    report($exception);

                    $this->sendError(
                        connection: $connection,
                        message: (
                            $isPublicDemo
                            || $exception instanceof PublicDemoException
                        )
                            ? 'Public voice demo request failed.'
                            : $exception->getMessage(),
                    );
                }
            },
        );

        $server->onDisconnect(
            function (
                Server $server,
                Connection $connection,
            ) use (&$sessions): void {
                $this->cleanupSession(
                    connection: $connection,
                    sessions: $sessions,
                );
            },
        );

        $server->onError(
            function (
                Server $server,
                ?Connection $connection,
                ExceptionInterface $exception,
            ): void {
                if (
                    $exception
                    instanceof ConnectionClosedException
                ) {
                    return;
                }

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
            'Default profile: '
                .self::DEFAULT_PROFILE,
        );

        $this->line(
            'Live profiles: '
                .implode(
                    ', ',
                    self::LIVE_PROFILES,
                ),
        );

        $server->start(
            0.005,
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{
     *     session: LiveSpeechRecognitionSession,
     *     profile: string,
     *     source_language: Language,
     *     translation: TranslationProvider,
     *     text_to_speech: StreamingTextToSpeechProvider,
     *     public_demo: PublicDemoWebSocketSession|null
     * }>  $sessions
     *
     * @throws JsonException
     */
    private function handleTextMessage(
        Connection $connection,
        Text $message,
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

        $type =
            $payload['type']
            ?? null;

        if ($type === 'start') {
            $this->startSession(
                connection: $connection,
                payload: $payload,
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
     * @param  array<int, array{
     *     session: LiveSpeechRecognitionSession,
     *     profile: string,
     *     source_language: Language,
     *     translation: TranslationProvider,
     *     text_to_speech: StreamingTextToSpeechProvider,
     *     public_demo: PublicDemoWebSocketSession|null
     * }>  $sessions
     */
    private function handleBinaryMessage(
        Connection $connection,
        Binary $message,
        array &$sessions,
    ): void {
        $key =
            spl_object_id(
                $connection,
            );

        $sessionContext =
            $sessions[$key]
            ?? null;

        if ($sessionContext === null) {
            throw new LogicException(
                'No active live speech recognition session.',
            );
        }

        $audio =
            $message->getContent();

        $publicDemo =
            $sessionContext['public_demo'];

        if (
            $publicDemo
            instanceof PublicDemoWebSocketSession
        ) {
            $publicDemo->acceptAudioChunk(
                $audio,
            );
        }

        $sessionContext['session']->pushAudio(
            $audio,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array{
     *     session: LiveSpeechRecognitionSession,
     *     profile: string,
     *     source_language: Language,
     *     translation: TranslationProvider,
     *     text_to_speech: StreamingTextToSpeechProvider,
     *     public_demo: PublicDemoWebSocketSession|null
     * }>  $sessions
     */
    private function startSession(
        Connection $connection,
        array $payload,
        array &$sessions,
    ): void {
        $key =
            spl_object_id(
                $connection,
            );

        if (
            isset($sessions[$key])
            && $sessions[$key]['session']->isRunning()
        ) {
            throw new LogicException(
                'A live speech recognition session is already active.',
            );
        }

        $mimeType =
            $payload['mime_type']
            ?? null;

        if (
            ! is_string($mimeType)
            || trim($mimeType) === ''
        ) {
            throw new InvalidArgumentException(
                'Audio MIME type is required.',
            );
        }

        $publicDemoSession =
            null;

        $demoToken =
            $payload['demo_token']
            ?? null;

        if ($demoToken !== null) {
            if (
                ! is_string($demoToken)
                || trim($demoToken) === ''
            ) {
                throw new PublicDemoException(
                    'Invalid public demo token.',
                );
            }

            $publicDemoSession =
                PublicDemoWebSocketSession::consume(
                    tokenStore: app(
                        PublicDemoTokenStore::class,
                    ),

                    token: trim(
                        $demoToken,
                    ),
                );

            $profileName =
                $publicDemoSession->profile();

            $sourceLanguage =
                $publicDemoSession
                    ->sourceLanguage();
        } else {
            if (
                ! config(
                    'live_pipeline.lab_enabled',
                    false,
                )
            ) {
                throw new PublicDemoException(
                    'A public demo token is required.',
                );
            }

            $profileName =
                $payload['profile']
                ?? self::DEFAULT_PROFILE;

            $sourceLanguageValue =
                $payload['source_language']
                ?? null;

            if (
                ! is_string(
                    $sourceLanguageValue,
                )
            ) {
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
        }

        if (
            ! is_string($profileName)
            || ! in_array(
                $profileName,
                self::LIVE_PROFILES,
                true,
            )
        ) {
            if (
                $publicDemoSession
                instanceof PublicDemoWebSocketSession
            ) {
                $publicDemoSession->release();

                throw new PublicDemoException(
                    'Public demo pipeline is unavailable.',
                );
            }

            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported live pipeline profile [%s].',
                    is_scalar($profileName)
                        ? (string) $profileName
                        : 'invalid',
                ),
            );
        }

        try {
            [
                $speechToText,
                $translation,
                $textToSpeech,
            ] = $this->resolvePipelineProviders(
                $profileName,
            );

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
        } catch (Throwable $exception) {
            if (
                $publicDemoSession
                instanceof PublicDemoWebSocketSession
            ) {
                $publicDemoSession->release();

                throw new PublicDemoException(
                    'Public voice demo could not start.',
                    previous: $exception,
                );
            }

            throw $exception;
        }

        /*
         * Providers are deliberately stored with the session.
         *
         * Resolving another profile may mutate Laravel config,
         * but this run keeps the provider instances created
         * from the configuration selected at START.
         */
        $sessions[$key] = [
            'session' => $session,
            'profile' => $profileName,
            'source_language' => $sourceLanguage,
            'translation' => $translation,
            'text_to_speech' => $textToSpeech,
            'public_demo' => $publicDemoSession,
        ];

        $this->sendJson(
            $connection,
            [
                'type' => 'session_started',

                'profile' => $profileName,

                'source_language' => $sourceLanguage->value,

                'target_language' => $this
                    ->targetLanguage(
                        $sourceLanguage,
                    )
                    ->value,

                'mime_type' => $mimeType,
            ],
        );
    }

    /**
     * @param  array<int, array{
     *     session: LiveSpeechRecognitionSession,
     *     profile: string,
     *     source_language: Language,
     *     translation: TranslationProvider,
     *     text_to_speech: StreamingTextToSpeechProvider,
     *     public_demo: PublicDemoWebSocketSession|null
     * }>  $sessions
     */
    private function finishSession(
        Connection $connection,
        array &$sessions,
    ): void {
        $key =
            spl_object_id(
                $connection,
            );

        $sessionContext =
            $sessions[$key]
            ?? null;

        if ($sessionContext === null) {
            throw new LogicException(
                'No active live speech recognition session.',
            );
        }

        $session =
            $sessionContext['session'];

        $profileName =
            $sessionContext['profile'];

        $sourceLanguage =
            $sessionContext['source_language'];

        $translation =
            $sessionContext['translation'];

        $textToSpeech =
            $sessionContext['text_to_speech'];

        $publicDemo =
            $sessionContext['public_demo'];

        if (
            $publicDemo
            instanceof PublicDemoWebSocketSession
        ) {
            $publicDemo->finishRecording();
        }

        $targetLanguage =
            $this->targetLanguage(
                $sourceLanguage,
            );

        $stoppedAt =
            hrtime(true);

        $speechResult =
            $session->finish();

        $sttCompletedAt =
            hrtime(true);

        $translationResult =
            $translation->translate(
                text: $speechResult->text,
                sourceLanguage: $sourceLanguage,
                targetLanguage: $targetLanguage,
            );

        $translationCompletedAt =
            hrtime(true);

        $stopToSttMs =
            $this->millisecondsBetween(
                $stoppedAt,
                $sttCompletedAt,
            );

        $translationMs =
            $this->millisecondsBetween(
                $sttCompletedAt,
                $translationCompletedAt,
            );

        $stopToTranslationMs =
            $this->millisecondsBetween(
                $stoppedAt,
                $translationCompletedAt,
            );

        $this->sendJson(
            $connection,
            [
                'type' => 'translation_completed',

                'profile' => $profileName,

                'text' => $speechResult->text,

                'translated_text' => $translationResult->text,

                'source_language' => $sourceLanguage->value,

                'target_language' => $targetLanguage->value,

                'server_stop_to_stt_ms' => round(
                    $stopToSttMs,
                    2,
                ),

                'translation_ms' => round(
                    $translationMs,
                    2,
                ),

                'server_stop_to_translation_ms' => round(
                    $stopToTranslationMs,
                    2,
                ),
            ],
        );

        $ttsStartedAt =
            hrtime(true);

        $firstTtsAudioAt =
            null;

        $this->sendJson(
            $connection,
            [
                'type' => 'tts_started',

                'profile' => $profileName,

                'format' => 'pcm16',

                'sample_rate_hz' => self::TTS_SAMPLE_RATE,

                'channels' => self::TTS_CHANNELS,

                'bytes_per_sample' => self::TTS_BYTES_PER_SAMPLE,
            ],
        );

        $synthesisResult =
            $textToSpeech->synthesize(
                text: $translationResult->text,
                targetLanguage: $targetLanguage,
                onAudioChunk: function (
                    string $chunk,
                ) use (
                    $connection,
                    &$firstTtsAudioAt,
                ): void {
                    if (
                        $firstTtsAudioAt
                        === null
                    ) {
                        $firstTtsAudioAt =
                            hrtime(true);
                    }

                    $connection->binary(
                        $chunk,
                    );
                },
            );

        $ttsCompletedAt =
            hrtime(true);

        $ttsFirstAudioMs =
            $firstTtsAudioAt !== null
            ? $this->millisecondsBetween(
                $ttsStartedAt,
                $firstTtsAudioAt,
            )
            : null;

        $stopToFirstTtsAudioMs =
            $firstTtsAudioAt !== null
            ? $this->millisecondsBetween(
                $stoppedAt,
                $firstTtsAudioAt,
            )
            : null;

        $ttsDurationMs =
            $this->millisecondsBetween(
                $ttsStartedAt,
                $ttsCompletedAt,
            );

        $stopToTtsCompletedMs =
            $this->millisecondsBetween(
                $stoppedAt,
                $ttsCompletedAt,
            );

        $this->sendJson(
            $connection,
            [
                'type' => 'completed',

                'profile' => $profileName,

                'text' => $speechResult->text,

                'translated_text' => $translationResult->text,

                'source_language' => $sourceLanguage->value,

                'target_language' => $targetLanguage->value,

                'server_stop_to_stt_ms' => round(
                    $stopToSttMs,
                    2,
                ),

                'translation_ms' => round(
                    $translationMs,
                    2,
                ),

                'server_stop_to_translation_ms' => round(
                    $stopToTranslationMs,
                    2,
                ),

                'tts_first_audio_ms' => $ttsFirstAudioMs !== null
                    ? round(
                        $ttsFirstAudioMs,
                        2,
                    )
                    : null,

                'server_stop_to_first_tts_audio_ms' => $stopToFirstTtsAudioMs !== null
                    ? round(
                        $stopToFirstTtsAudioMs,
                        2,
                    )
                    : null,

                'tts_duration_ms' => round(
                    $ttsDurationMs,
                    2,
                ),

                'server_stop_to_tts_completed_ms' => round(
                    $stopToTtsCompletedMs,
                    2,
                ),

                'translated_audio_bytes' => strlen(
                    $synthesisResult->audio,
                ),

                'translated_audio_duration_ms' => round(
                    $this->pcmDurationMilliseconds(
                        $synthesisResult->audio,
                    ),
                    2,
                ),
            ],
        );

        $this->cleanupSession(
            connection: $connection,
            sessions: $sessions,
        );
    }

    /**
     * @return array{
     *     0: StreamingSpeechToTextProvider,
     *     1: TranslationProvider,
     *     2: StreamingTextToSpeechProvider
     * }
     */
    private function resolvePipelineProviders(
        string $profileName,
    ): array {
        $profile = config(
            'benchmarks.translation.pipeline.providers.'
                .$profileName,
        );

        if (! is_array($profile)) {
            throw new RuntimeException(
                sprintf(
                    'Live pipeline profile [%s] is not configured.',
                    $profileName,
                ),
            );
        }

        $speechProfile =
            $profile['speech_to_text']
            ?? null;

        $translationProfile =
            $profile['translation']
            ?? null;

        $textToSpeechProfile =
            $profile['text_to_speech']
            ?? null;

        if (
            ! is_array($speechProfile)
            || ! is_array($translationProfile)
            || ! is_array($textToSpeechProfile)
        ) {
            throw new RuntimeException(
                sprintf(
                    'Live pipeline profile [%s] is invalid.',
                    $profileName,
                ),
            );
        }

        $speechContract =
            $speechProfile['contract']
            ?? null;

        $translationContract =
            $translationProfile['contract']
            ?? null;

        $textToSpeechContract =
            $textToSpeechProfile['contract']
            ?? null;

        $speechConfig =
            $speechProfile['config']
            ?? null;

        $translationConfig =
            $translationProfile['config']
            ?? null;

        $textToSpeechConfig =
            $textToSpeechProfile['config']
            ?? null;

        if (
            ! is_string($speechContract)
            || ! is_string($translationContract)
            || ! is_string($textToSpeechContract)
            || ! is_array($speechConfig)
            || ! is_array($translationConfig)
            || ! is_array($textToSpeechConfig)
        ) {
            throw new RuntimeException(
                sprintf(
                    'Live pipeline provider configuration [%s] is invalid.',
                    $profileName,
                ),
            );
        }

        /*
         * Provider settings come directly from the benchmark
         * profile selected by the browser.
         */
        config([
            ...$speechConfig,
            ...$translationConfig,
            ...$textToSpeechConfig,
        ]);

        /*
         * SpeechClient is a singleton and its API endpoint
         * depends on the selected Google profile.
         */
        app()->forgetInstance(
            SpeechClient::class,
        );

        app()->forgetInstance(
            $speechContract,
        );

        app()->forgetInstance(
            $translationContract,
        );

        app()->forgetInstance(
            $textToSpeechContract,
        );

        $speechToText =
            app(
                $speechContract,
            );

        if (
            ! $speechToText
                instanceof StreamingSpeechToTextProvider
        ) {
            throw new RuntimeException(
                'Live pipeline speech provider must implement '
                    .StreamingSpeechToTextProvider::class
                    .'.',
            );
        }

        $translation =
            app(
                $translationContract,
            );

        if (
            ! $translation
                instanceof TranslationProvider
        ) {
            throw new RuntimeException(
                'Live pipeline translation provider must implement '
                    .TranslationProvider::class
                    .'.',
            );
        }

        $textToSpeech =
            app(
                $textToSpeechContract,
            );

        if (
            ! $textToSpeech
                instanceof StreamingTextToSpeechProvider
        ) {
            throw new RuntimeException(
                'Live pipeline text-to-speech provider must implement '
                    .StreamingTextToSpeechProvider::class
                    .'.',
            );
        }

        return [
            $speechToText,
            $translation,
            $textToSpeech,
        ];
    }

    /**
     * @param  array<int, array{
     *     session: LiveSpeechRecognitionSession,
     *     profile: string,
     *     source_language: Language,
     *     translation: TranslationProvider,
     *     text_to_speech: StreamingTextToSpeechProvider,
     *     public_demo: PublicDemoWebSocketSession|null
     * }>  $sessions
     */
    private function cleanupSession(
        Connection $connection,
        array &$sessions,
    ): bool {
        $key =
            spl_object_id(
                $connection,
            );

        $sessionContext =
            $sessions[$key]
            ?? null;

        if ($sessionContext === null) {
            return false;
        }

        $publicDemo =
            $sessionContext['public_demo'];

        if (
            $publicDemo
            instanceof PublicDemoWebSocketSession
        ) {
            $publicDemo->release();
        }

        unset(
            $sessions[$key],
        );

        return $publicDemo
            instanceof PublicDemoWebSocketSession;
    }

    private function targetLanguage(
        Language $sourceLanguage,
    ): Language {
        return match ($sourceLanguage) {
            Language::Russian => Language::English,

            Language::English => Language::Russian,
        };
    }

    private function millisecondsBetween(
        int $startedAt,
        int $endedAt,
    ): float {
        return (
            $endedAt
            - $startedAt
        ) / 1_000_000;
    }

    private function pcmDurationMilliseconds(
        string $audio,
    ): float {
        return (
            strlen($audio)
            / (
                self::TTS_SAMPLE_RATE
                * self::TTS_CHANNELS
                * self::TTS_BYTES_PER_SAMPLE
            )
        ) * 1000;
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
