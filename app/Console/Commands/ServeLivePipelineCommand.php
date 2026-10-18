<?php

namespace App\Console\Commands;

use App\Contracts\StreamingSpeechToTextProvider;
use App\Contracts\StreamingTextToSpeechProvider;
use App\Contracts\TranslationProvider;
use App\Enums\Language;
use App\Exceptions\PublicDemoException;
use App\Services\Conversation\AsyncSttBridgeClient;
use App\Services\Conversation\ConversationConnectionRegistry;
use App\Services\Conversation\ConversationPendingWorkerRegistry;
use App\Services\Conversation\ConversationStore;
use App\Services\Conversation\ConversationTurnManager;
use App\Services\Conversation\ConversationWebSocketHandler;
use App\Services\Conversation\ConversationWorkerManager;
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

/**
 * @phpstan-type LiveSession array{
 *     session: LiveSpeechRecognitionSession,
 *     profile: string,
 *     source_language: Language,
 *     translation: TranslationProvider,
 *     text_to_speech: StreamingTextToSpeechProvider,
 *     public_demo: PublicDemoWebSocketSession|null,
 *     stopping?: bool
 * }
 * @phpstan-type IncrementalSession array{
 *     session: AsyncSttBridgeClient,
 *     profile: string,
 *     source_language: Language,
 *     public_demo: null,
 *     connection: Connection,
 *     final_texts: list<string>,
 *     stopping: bool
 * }
 * @phpstan-type ConversationSession LiveSession|IncrementalSession
 */
final class ServeLivePipelineCommand extends Command
{
    /** @var array<string, float> */
    private array $conversationStopTimes = [];

    /** @var array<string, true> */
    private array $conversationFirstPcmLogged = [];

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
         * @var array<int, ConversationSession> $sessions
         */
        $sessions = [];
        // One serial translation/TTS worker per incremental STT turn.
        // Keep the queue alive after STT itself is closed at Stop.
        /** @var array<string, array{owner: Connection, peer: Connection}> $incrementalTurns */
        $incrementalTurns = [];

        $conversationHandler = new ConversationWebSocketHandler(
            app(ConversationStore::class),
            new ConversationConnectionRegistry,
            new ConversationTurnManager,
        );

        $pendingWorkers = new ConversationPendingWorkerRegistry;

        $workers = config('conversation.async_worker_enabled', false)
            ? new ConversationWorkerManager(
                workerCommand: config(
                    'conversation.async_worker_command',
                    [
                        PHP_BINARY,
                        base_path('artisan'),
                        'conversation:run-worker',
                    ],
                ),
                timeoutSeconds: (float) config(
                    'conversation.async_worker_timeout_seconds',
                    60,
                ),
                persistentMode: ! app()->environment('testing'),
            )
            : null;

        // Incremental STT runs only with the existing periodic worker tick.
        $incrementalStt = (bool) config('conversation.incremental_stt_enabled', false)
            && $workers !== null;

        $server = new Server(
            port: $port,
        );

        $server->onText(
            function (
                Server $server,
                Connection $connection,
                Text $message,
            ) use (
                &$sessions,
                $conversationHandler,
                $workers,
                $pendingWorkers,
                $incrementalStt,
            ): void {
                try {
                    $this->handleTextMessage(
                        connection: $connection,
                        message: $message,
                        sessions: $sessions,
                        conversationHandler: $conversationHandler,
                        workers: $workers,
                        pendingWorkers: $pendingWorkers,
                        incrementalStt: $incrementalStt,
                    );
                } catch (Throwable $exception) {
                    report($exception);

                    $isPublicDemo = false;

                    try {
                        $isPublicDemo = $this->cancelSession(
                            connection: $connection,
                            sessions: $sessions,
                        );
                    } catch (Throwable $cleanupException) {
                        report($cleanupException);
                    }

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
            ) use (&$sessions, $conversationHandler): void {
                try {
                    if ($conversationHandler->isBound($connection)) {
                        if (! $conversationHandler->isCurrent($connection)) {
                            throw new LogicException(
                                'Stale conversation connection.',
                            );
                        }

                        if (! config('conversation.audio_enabled', false)) {
                            throw new LogicException(
                                'Conversation audio is disabled.',
                            );
                        }

                        if (! $conversationHandler->ownsTurn($connection)) {
                            throw new LogicException(
                                'Conversation audio rejected: connection does not own the turn.',
                            );
                        }

                        $this->handleBinaryMessage(
                            connection: $connection,
                            message: $message,
                            sessions: $sessions,
                        );

                        return;
                    }

                    $this->handleBinaryMessage(
                        connection: $connection,
                        message: $message,
                        sessions: $sessions,
                    );
                } catch (Throwable $exception) {
                    report($exception);

                    $isPublicDemo = false;

                    try {
                        $isPublicDemo = $this->cancelSession(
                            connection: $connection,
                            sessions: $sessions,
                        );
                    } catch (Throwable $cleanupException) {
                        report($cleanupException);
                    }

                    if ($conversationHandler->isBound($connection)) {
                        try {
                            if ($conversationHandler->isCurrent($connection)) {
                                $conversationHandler->abortTurn(
                                    $connection,
                                    function (Connection $to, array $data): void {
                                        $this->sendJson($to, $data);
                                    },
                                );
                            }
                        } catch (Throwable $cleanupException) {
                            report($cleanupException);
                        }
                    }

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
            ) use (
                &$sessions,
                $conversationHandler,
                $pendingWorkers,
                $workers,
                &$incrementalTurns,
            ): void {
                try {
                    foreach ($incrementalTurns as $key => $turn) {
                        if ($turn['owner'] === $connection || $turn['peer'] === $connection) {
                            unset($incrementalTurns[$key]);
                        }
                    }
                    foreach ($pendingWorkers->all() as $turnId => $job) {
                        if (
                            $job['owner'] !== $connection
                            && $job['peer'] !== $connection
                        ) {
                            continue;
                        }

                        try {
                            $workers?->cancel($turnId);
                        } finally {
                            $pendingWorkers->remove($turnId);

                            $owner = $job['owner'];

                            if (
                                $owner !== $connection
                                && $conversationHandler->isCurrent($owner)
                                && $conversationHandler->ownsTurn($owner)
                            ) {
                                try {
                                    $conversationHandler->abortTurn(
                                        $owner,
                                        function (Connection $to, array $data): void {
                                            $this->sendJson($to, $data);
                                        },
                                    );
                                } catch (Throwable $cleanupException) {
                                    report($cleanupException);
                                }
                            }
                        }
                    }

                    $this->cancelSession(
                        connection: $connection,
                        sessions: $sessions,
                    );
                } catch (Throwable $exception) {
                    report($exception);
                } finally {
                    $conversationHandler->disconnect(
                        $connection,
                        function (Connection $to, array $payload): void {
                            $this->sendJson($to, $payload);
                        },
                    );
                }
            },
        );

        if ($workers !== null) {
            $server->onTick(
                function (Server $server) use (
                    $workers,
                    $pendingWorkers,
                    $conversationHandler,
                    &$sessions,
                    &$incrementalTurns,
                ): void {
                    // Poll each bridge even if no WebSocket audio arrives this tick.
                    foreach ($sessions as $key => $context) {
                        if (! ($context['session'] instanceof AsyncSttBridgeClient)) {
                            continue;
                        }
                        $client = $context['session'];
                        $owner = $context['connection'];
                        if (! isset($incrementalTurns[$key])) {
                            $incrementalTurns[$key] = [
                                'owner' => $owner,
                                'peer' => $conversationHandler->currentPeer($owner),
                                'source_language' => $context['source_language'],
                                'queue' => [],
                                'active' => null,
                                'done' => false,
                            ];
                        }
                        try {
                            if (! $owner->isConnected()
                                || ! $conversationHandler->isCurrent($owner)
                                || ! $conversationHandler->ownsTurn($owner)
                                || $conversationHandler->currentPeer($owner) === null
                            ) {
                                $client->cancel();
                                unset($sessions[$key], $incrementalTurns[$key]);

                                continue;
                            }
                            foreach ($client->poll() as $event) {
                                if ($event['type'] === 'final') {
                                    $sessions[$key]['final_texts'][] = $event['text'];
                                    $incrementalTurns[$key]['queue'][] = $event['text'];
                                    $this->sendJson($owner, [
                                        'type' => 'conversation_transcript',
                                        'text' => implode(' ', $sessions[$key]['final_texts']),
                                        'is_final' => true,
                                        'segment' => $event['segment'],
                                    ]);
                                } elseif ($event['type'] === 'interim') {
                                    $this->sendJson($owner, [
                                        'type' => 'conversation_transcript',
                                        'text' => trim(implode(' ', $sessions[$key]['final_texts']).' '.$event['text']),
                                        'is_final' => false,
                                    ]);
                                } elseif ($event['type'] === 'done') {
                                    if (! $context['stopping']) {
                                        throw new LogicException('STT bridge ended before stop.');
                                    }
                                    $this->sendJson($owner, [
                                        'type' => 'conversation_recognition_completed',
                                        'text' => implode(' ', $sessions[$key]['final_texts']),
                                    ]);
                                    $client->close();
                                    unset($sessions[$key]);
                                    $incrementalTurns[$key]['done'] = true;
                                    // Release only after all queued segment audio has been sent.
                                }
                            }
                        } catch (Throwable $exception) {
                            report($exception);
                            $client->cancel();
                            unset($sessions[$key], $incrementalTurns[$key]);
                            try {
                                if ($conversationHandler->ownsTurn($owner)) {
                                    $conversationHandler->abortTurn($owner, function (Connection $to, array $data): void {
                                        $this->sendJson($to, $data);
                                    });
                                }
                            } catch (Throwable $cleanupException) {
                                report($cleanupException);
                            }
                            if ($owner->isConnected()) {
                                $this->sendError($owner, 'Incremental STT failed.');
                            }
                        }
                    }

                    // Start the next segment only after the previous TTS stream ends.
                    foreach ($incrementalTurns as $key => $turn) {
                        $owner = $turn['owner'];
                        $peer = $turn['peer'];
                        if (! $owner->isConnected()
                            || $peer === null || ! $peer->isConnected()
                            || ! $conversationHandler->isCurrent($owner)
                            || ! $conversationHandler->isCurrent($peer)
                            || ! $conversationHandler->ownsTurn($owner)
                            || $conversationHandler->currentPeer($owner) !== $peer
                        ) {
                            if ($turn['active'] !== null) {
                                $workers->cancel($turn['active']);
                                $pendingWorkers->remove($turn['active']);
                            }
                            unset($incrementalTurns[$key]);
                            if ($conversationHandler->isCurrent($owner)
                                && $conversationHandler->ownsTurn($owner)) {
                                $conversationHandler->abortTurn($owner, function (Connection $to, array $data): void {
                                    $this->sendJson($to, $data);
                                });
                            }

                            continue;
                        }

                        if ($turn['active'] !== null) {
                            continue;
                        }
                        if ($turn['queue'] === []) {
                            if ($turn['done']) {
                                unset($incrementalTurns[$key]);
                                $conversationHandler->stopTurn($owner, function (Connection $to, array $data): void {
                                    $this->sendJson($to, $data);
                                });
                            }

                            continue;
                        }

                        $text = array_shift($incrementalTurns[$key]['queue']);
                        $turnId = bin2hex(random_bytes(16));
                        $sourceLanguage = $turn['source_language'];
                        $targetLanguage = $this->targetLanguage($sourceLanguage);
                        try {
                            $pendingWorkers->add(
                                turnId: $turnId,
                                owner: $owner,
                                peer: $peer,
                                sourceText: $text,
                                sourceLanguage: $sourceLanguage,
                                targetLanguage: $targetLanguage,
                            );
                            $incrementalTurns[$key]['active'] = $turnId;
                            $workers->startJob($turnId, [
                                'turn_id' => $turnId,
                                'source_text' => $text,
                                'source_language' => $sourceLanguage->value,
                                'target_language' => $targetLanguage->value,
                                'stream' => true,
                                'server_worker_requested_at' => microtime(true),
                            ]);
                        } catch (Throwable $exception) {
                            report($exception);
                            $pendingWorkers->remove($turnId);
                            unset($incrementalTurns[$key]);
                            if ($conversationHandler->ownsTurn($owner)) {
                                $conversationHandler->abortTurn($owner, function (Connection $to, array $data): void {
                                    $this->sendJson($to, $data);
                                });
                            }
                            if ($owner->isConnected()) {
                                $this->sendError($owner, 'Incremental translation failed.');
                            }
                        }
                    }

                    foreach ($pendingWorkers->all() as $turnId => $job) {
                        $owner = $job['owner'];
                        $peer = $job['peer'];

                        if (
                            ! $owner->isConnected()
                            || ! $peer->isConnected()
                            || ! $conversationHandler->isCurrent($owner)
                            || ! $conversationHandler->isCurrent($peer)
                            || ! $conversationHandler->ownsTurn($owner)
                            || $conversationHandler->currentPeer($owner) !== $peer
                        ) {
                            $workers->cancel($turnId);
                            $pendingWorkers->remove($turnId);
                            $ownerKey = spl_object_id($owner);
                            if (($incrementalTurns[$ownerKey]['active'] ?? null) === $turnId) {
                                unset($incrementalTurns[$ownerKey]);
                            }

                            try {
                                if (
                                    $conversationHandler->isCurrent($owner)
                                    && $conversationHandler->ownsTurn($owner)
                                ) {
                                    $conversationHandler->abortTurn(
                                        $owner,
                                        function (Connection $to, array $data): void {
                                            $this->sendJson($to, $data);
                                        },
                                    );
                                }
                            } catch (Throwable $cleanupException) {
                                report($cleanupException);
                            }

                            continue;
                        }

                        try {
                            $events = $workers->pollStream($turnId);

                            foreach ($events as $event) {
                                if ($event['type'] === 'translation') {
                                    logger()->info('CONVERSATION_LATENCY translation', [
                                        'turn_id' => $turnId,
                                        'stop_to_translation_ms' => isset(
                                            $this->conversationStopTimes[$turnId]
                                        ) ? round(
                                            (microtime(true)
                                                - $this->conversationStopTimes[$turnId]) * 1000,
                                        ) : null,
                                        'startup_ms' => $event['startup_ms'] ?? null,
                                        'worker_elapsed_ms' => $event['worker_elapsed_ms'] ?? null,
                                    ]);

                                    $payload = [
                                        'type' => 'conversation_translation_completed',
                                        'text' => $job['source_text'],
                                        'translated_text' => $event['translated_text'],
                                        'source_language' => $job['source_language']->value,
                                        'target_language' => $job['target_language']->value,
                                    ];

                                    $this->sendJson($owner, $payload);
                                    $this->sendJson($peer, $payload);

                                    continue;
                                }

                                if ($event['type'] === 'tts_started') {
                                    $this->sendJson($peer, [
                                        'type' => 'conversation_tts_started',
                                        'format' => 'pcm16',
                                        'sample_rate_hz' => self::TTS_SAMPLE_RATE,
                                        'channels' => self::TTS_CHANNELS,
                                        'bytes_per_sample' => self::TTS_BYTES_PER_SAMPLE,
                                    ]);

                                    continue;
                                }

                                if ($event['type'] === 'audio_chunk') {
                                    if (
                                        ! isset($this->conversationFirstPcmLogged[$turnId])
                                        && isset($this->conversationStopTimes[$turnId])
                                    ) {
                                        logger()->info(
                                            'CONVERSATION_LATENCY stop_to_first_pcm',
                                            [
                                                'turn_id' => $turnId,
                                                'duration_ms' => round(
                                                    (microtime(true)
                                                        - $this->conversationStopTimes[$turnId])
                                                        * 1000,
                                                ),
                                            ],
                                        );

                                        $this->conversationFirstPcmLogged[$turnId] = true;
                                    }

                                    $peer->binary($event['audio']);

                                    continue;
                                }

                                if ($event['type'] === 'completed') {
                                    if (isset($event['worker_latency'])) {
                                        logger()->info(
                                            'CONVERSATION_LATENCY worker',
                                            [
                                                'turn_id' => $turnId,
                                                ...$event['worker_latency'],
                                            ],
                                        );
                                    }

                                    $this->sendJson($peer, [
                                        'type' => 'conversation_completed',
                                        'source_language' => $job['source_language']->value,
                                        'target_language' => $job['target_language']->value,
                                        'translated_audio_bytes' => $event['audio_bytes'],
                                    ]);

                                    $pendingWorkers->remove($turnId);
                                    unset(
                                        $this->conversationStopTimes[$turnId],
                                        $this->conversationFirstPcmLogged[$turnId],
                                    );

                                    $ownerKey = spl_object_id($owner);
                                    if (($incrementalTurns[$ownerKey]['active'] ?? null) === $turnId) {
                                        $incrementalTurns[$ownerKey]['active'] = null;

                                        // Keep the microphone turn owned until STT is done
                                        // AND there are no queued translated segments.
                                        continue;
                                    }

                                    $conversationHandler->stopTurn(
                                        $owner,
                                        function (Connection $to, array $data): void {
                                            $this->sendJson($to, $data);
                                        },
                                    );
                                }
                            }
                        } catch (Throwable $exception) {
                            report($exception);

                            $workers->cancel($turnId);
                            $pendingWorkers->remove($turnId);
                            $ownerKey = spl_object_id($owner);
                            if (($incrementalTurns[$ownerKey]['active'] ?? null) === $turnId) {
                                unset($incrementalTurns[$ownerKey]);
                                if (($sessions[$ownerKey]['session'] ?? null) instanceof AsyncSttBridgeClient) {
                                    $sessions[$ownerKey]['session']->cancel();
                                    unset($sessions[$ownerKey]);
                                }
                            }

                            try {
                                if ($conversationHandler->ownsTurn($owner)) {
                                    $conversationHandler->abortTurn(
                                        $owner,
                                        function (Connection $to, array $data): void {
                                            $this->sendJson($to, $data);
                                        },
                                    );
                                }
                            } catch (Throwable $cleanupException) {
                                report($cleanupException);
                            }

                            if ($owner->isConnected()) {
                                $this->sendError(
                                    connection: $owner,
                                    message: 'Conversation translation failed.',
                                );
                            }
                        }
                    }
                },
            );
        }

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
     * @param  array<int, ConversationSession>  $sessions
     *
     * @throws JsonException
     */
    private function handleTextMessage(
        Connection $connection,
        Text $message,
        array &$sessions,
        ConversationWebSocketHandler $conversationHandler,
        ?ConversationWorkerManager $workers,
        ConversationPendingWorkerRegistry $pendingWorkers,
        bool $incrementalStt = false,
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

        if ($type === 'conversation_join') {
            $code = $payload['code'] ?? null;
            $token = $payload['token'] ?? null;

            if (! is_string($code) || ! is_string($token)) {
                throw new InvalidArgumentException(
                    'Conversation code and token are required.',
                );
            }

            if (isset($sessions[spl_object_id($connection)])) {
                throw new LogicException(
                    'Connection already has an active session.',
                );
            }

            $conversationHandler->join(
                $connection,
                $code,
                $token,
                function (Connection $to, array $data) use ($incrementalStt): void {
                    if ($data['type'] === 'conversation_joined') {
                        $data['incremental_stt_enabled'] = $incrementalStt;
                    }
                    $this->sendJson($to, $data);
                },
            );

            return;
        }

        if ($conversationHandler->isBound($connection)) {
            if (! $conversationHandler->isCurrent($connection)) {
                throw new LogicException(
                    'Stale conversation connection.',
                );
            }

            if ($type === 'conversation_start') {
                $audioEnabled = config(
                    'conversation.audio_enabled',
                    false,
                );

                try {
                    $conversationHandler->startTurn(
                        $connection,
                        function (Connection $to, array $data): void {
                            $this->sendJson($to, $data);
                        },
                        $audioEnabled
                            ? function () use (
                                $connection,
                                $payload,
                                &$sessions,
                                $conversationHandler,
                                $incrementalStt,
                            ): void {
                                $this->startConversationSession(
                                    $connection,
                                    $payload,
                                    $sessions,
                                    $conversationHandler,
                                    $incrementalStt,
                                );
                            }
                        : null,
                    );
                } catch (Throwable $exception) {
                    if ($audioEnabled) {
                        try {
                            $this->cancelSession($connection, $sessions);
                        } catch (Throwable $cleanupException) {
                            report($cleanupException);
                        }
                    }

                    throw $exception;
                }

                return;
            }

            if ($type === 'conversation_stop') {
                $send = function (
                    Connection $to,
                    array $data,
                ): void {
                    $this->sendJson($to, $data);
                };

                $audioEnabled = config(
                    'conversation.audio_enabled',
                    false,
                );

                if (
                    $workers !== null
                    && $pendingWorkers->hasOwner($connection)
                    && ! (($sessions[spl_object_id($connection)]['session'] ?? null)
                        instanceof AsyncSttBridgeClient)
                ) {
                    $this->sendError(
                        connection: $connection,
                        message: 'Conversation translation is already processing.',
                    );

                    return;
                }

                if (
                    $audioEnabled
                    && ! $conversationHandler->ownsTurn($connection)
                ) {
                    throw new LogicException(
                        'Connection does not own the conversation turn.',
                    );
                }

                try {
                    if ($audioEnabled) {
                        $active = $sessions[spl_object_id($connection)] ?? null;
                        if (($active['session'] ?? null) instanceof AsyncSttBridgeClient) {
                            if ($active['stopping']) {
                                throw new LogicException('STT stop already requested.');
                            }
                            $active['session']->finish();
                            $active['stopping'] = true;
                            $sessions[spl_object_id($connection)] = $active;

                            /** @var array<int, ConversationSession> $sessions */

                            // onTick emits recognition_completed and releases turn at done.
                            return;
                        }
                        if ($workers !== null) {
                            $this->startAsyncConversationWorker(
                                connection: $connection,
                                sessions: $sessions,
                                conversationHandler: $conversationHandler,
                                workers: $workers,
                                pendingWorkers: $pendingWorkers,
                            );

                            // Keep turn ownership until worker completion.
                            return;
                        }

                        $this->finishConversationSession(
                            $connection,
                            $sessions,
                            $conversationHandler,
                        );
                    }

                    $conversationHandler->stopTurn(
                        $connection,
                        $send,
                    );
                } catch (Throwable $exception) {
                    try {
                        if ($workers !== null) {
                            foreach (
                                $pendingWorkers->removeFor($connection) as $turnId
                            ) {
                                $workers->cancel($turnId);
                            }

                            $this->cancelSession(
                                connection: $connection,
                                sessions: $sessions,
                            );
                        }

                        $conversationHandler->abortTurn(
                            $connection,
                            $send,
                        );
                    } catch (Throwable $cleanupException) {
                        report($cleanupException);
                    }

                    throw $exception;
                }

                return;
            }

            throw new LogicException(
                'Unsupported conversation control message.',
            );
        }

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
     * @param  array<int, ConversationSession>  $sessions
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

        if (
            $sessionContext['session'] instanceof AsyncSttBridgeClient
            && $sessionContext['stopping']
        ) {
            throw new LogicException('Audio received after incremental STT stop.');
        }

        $sessionContext['session']->pushAudio(
            $audio,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, ConversationSession>  $sessions
     */
    private function startConversationSession(
        Connection $connection,
        array $payload,
        array &$sessions,
        ConversationWebSocketHandler $conversationHandler,
        bool $incrementalStt = false,
    ): void {
        $key = spl_object_id($connection);

        if (isset($sessions[$key])) {
            throw new LogicException(
                'Connection already has an active session.',
            );
        }

        $mimeType = $payload['mime_type'] ?? null;

        if (! is_string($mimeType) || trim($mimeType) === '') {
            throw new InvalidArgumentException(
                'Audio MIME type is required.',
            );
        }

        $context = $conversationHandler->currentContext($connection);

        $language = Language::tryFrom($context['language']);

        if ($language === null) {
            throw new InvalidArgumentException(
                'Unsupported conversation language.',
            );
        }

        if ($incrementalStt) {
            if ($mimeType !== 'audio/pcm;rate=24000') {
                throw new InvalidArgumentException('Incremental STT requires 24kHz PCM16.');
            }
            $bridge = new AsyncSttBridgeClient(
                host: (string) config('conversation.stt_bridge_host', 'host.docker.internal'),
                port: (int) config('conversation.stt_bridge_port', 8767),
            );
            $bridge->start($language === Language::Russian ? 'ru-RU' : 'en-US', 24000);
            $sessions[$key] = [
                'session' => $bridge,
                'profile' => self::DEFAULT_PROFILE,
                'source_language' => $language,
                'public_demo' => null,
                'connection' => $connection,
                'final_texts' => [],
                'stopping' => false,
            ];

            return;
        }

        [
            $speechToText,
            $translation,
            $textToSpeech,
        ] = $this->resolvePipelineProviders(
            self::DEFAULT_PROFILE,
        );

        $session = new LiveSpeechRecognitionSession(
            speechToText: $speechToText,
            mimeType: $mimeType,
            sourceLanguage: $language,
            onTranscript: function (
                string $text,
                bool $isFinal,
            ) use ($connection): void {
                $this->sendJson($connection, [
                    'type' => 'conversation_transcript',
                    'text' => $text,
                    'is_final' => $isFinal,
                ]);
            },
        );

        $session->start();

        $sessions[$key] = [
            'session' => $session,
            'profile' => self::DEFAULT_PROFILE,
            'source_language' => $language,
            'translation' => $translation,
            'text_to_speech' => $textToSpeech,
            'public_demo' => null,
        ];
    }

    /**
     * @param  array<int, ConversationSession>  $sessions
     */
    private function startAsyncConversationWorker(
        Connection $connection,
        array &$sessions,
        ConversationWebSocketHandler $conversationHandler,
        ConversationWorkerManager $workers,
        ConversationPendingWorkerRegistry $pendingWorkers,
    ): void {
        if ($pendingWorkers->hasOwner($connection)) {
            throw new LogicException(
                'Conversation translation is already processing.',
            );
        }

        $key = spl_object_id($connection);
        $context = $sessions[$key] ?? null;

        if ($context === null) {
            throw new LogicException(
                'No active conversation recording.',
            );
        }

        if (! $conversationHandler->ownsTurn($connection)) {
            throw new LogicException(
                'Connection does not own the conversation turn.',
            );
        }

        $peer = $conversationHandler->currentPeer($connection);

        if ($peer === null) {
            throw new LogicException(
                'Conversation peer is not connected.',
            );
        }

        $stopReceivedAt = microtime(true);
        $sttStarted = $stopReceivedAt;
        $speechResult = $context['session']->finish();

        logger()->info('CONVERSATION_LATENCY stt_finish', [
            'duration_ms' => round((microtime(true) - $sttStarted) * 1000),
        ]);

        $this->sendJson($connection, [
            'type' => 'conversation_recognition_completed',
            'text' => $speechResult->text,
        ]);

        if (
            ! $conversationHandler->ownsTurn($connection)
            || $conversationHandler->currentPeer($connection) !== $peer
        ) {
            throw new LogicException(
                'Conversation peer changed during processing.',
            );
        }

        $sourceLanguage = $context['source_language'];
        $targetLanguage = $this->targetLanguage($sourceLanguage);
        $turnId = bin2hex(random_bytes(16));

        $pendingWorkers->add(
            turnId: $turnId,
            owner: $connection,
            peer: $peer,
            sourceText: $speechResult->text,
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
        );

        $this->conversationStopTimes[$turnId] = $stopReceivedAt;

        logger()->info('CONVERSATION_LATENCY turn_started', [
            'turn_id' => $turnId,
            'stt_finish_ms' => round(
                (microtime(true) - $stopReceivedAt) * 1000,
            ),
        ]);

        try {
            $workers->startJob($turnId, [
                'turn_id' => $turnId,
                'source_text' => $speechResult->text,
                'source_language' => $sourceLanguage->value,
                'target_language' => $targetLanguage->value,
                'stream' => true,
                'server_worker_requested_at' => microtime(true),
            ]);
        } catch (Throwable $exception) {
            $pendingWorkers->remove($turnId);

            throw $exception;
        }

        $this->cleanupSession($connection, $sessions);
    }

    /**
     * @param  array<int, ConversationSession>  $sessions
     */
    private function finishConversationSession(
        Connection $connection,
        array &$sessions,
        ConversationWebSocketHandler $conversationHandler,
    ): void {
        $key = spl_object_id($connection);
        $context = $sessions[$key] ?? null;

        if ($context === null) {
            throw new LogicException(
                'No active conversation recording.',
            );
        }

        if (! $conversationHandler->ownsTurn($connection)) {
            throw new LogicException(
                'Connection does not own the conversation turn.',
            );
        }

        $completed = false;

        try {
            $peer = $conversationHandler->currentPeer($connection);

            if ($peer === null) {
                throw new LogicException(
                    'Conversation peer is not connected.',
                );
            }

            $assertPeer = function () use (
                $connection,
                $peer,
                $conversationHandler,
            ): void {
                if (
                    ! $conversationHandler->ownsTurn($connection)
                    || $conversationHandler->currentPeer($connection) !== $peer
                ) {
                    throw new LogicException(
                        'Conversation peer changed during processing.',
                    );
                }
            };

            $speechResult = $context['session']->finish();

            $this->sendJson($connection, [
                'type' => 'conversation_recognition_completed',
                'text' => $speechResult->text,
            ]);

            $sourceLanguage = $context['source_language'];
            $targetLanguage = $this->targetLanguage(
                $sourceLanguage,
            );

            $assertPeer();

            $translationResult = $context['translation']->translate(
                text: $speechResult->text,
                sourceLanguage: $sourceLanguage,
                targetLanguage: $targetLanguage,
            );

            $assertPeer();

            $this->sendJson($peer, [
                'type' => 'conversation_translation_completed',
                'text' => $speechResult->text,
                'translated_text' => $translationResult->text,
                'source_language' => $sourceLanguage->value,
                'target_language' => $targetLanguage->value,
            ]);

            $assertPeer();

            $this->sendJson($peer, [
                'type' => 'conversation_tts_started',
                'format' => 'pcm16',
                'sample_rate_hz' => self::TTS_SAMPLE_RATE,
                'channels' => self::TTS_CHANNELS,
                'bytes_per_sample' => self::TTS_BYTES_PER_SAMPLE,
            ]);

            $synthesisResult = $context['text_to_speech']->synthesize(
                text: $translationResult->text,
                targetLanguage: $targetLanguage,
                onAudioChunk: function (string $chunk) use (
                    $peer,
                    $assertPeer,
                ): void {
                    $assertPeer();

                    $peer->binary($chunk);
                },
            );

            $assertPeer();

            $this->sendJson($peer, [
                'type' => 'conversation_completed',
                'source_language' => $sourceLanguage->value,
                'target_language' => $targetLanguage->value,
                'translated_audio_bytes' => strlen(
                    $synthesisResult->audio,
                ),
            ]);

            $completed = true;
        } finally {
            if ($completed) {
                $this->cleanupSession($connection, $sessions);
            } else {
                $this->cancelSession($connection, $sessions);
            }
        }
    }

    /**
     * @param  array<int, ConversationSession>  $sessions
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
     * @param  array<int, ConversationSession>  $sessions
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
     * @param  array<int, ConversationSession>  $sessions
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

        try {
            if (
                $publicDemo
                instanceof PublicDemoWebSocketSession
            ) {
                $publicDemo->release();
            }
        } finally {
            unset(
                $sessions[$key],
            );
        }

        return $publicDemo
            instanceof PublicDemoWebSocketSession;
    }

    /**
     * Cancel an active recording before releasing its resources.
     *
     * @param  array<int, ConversationSession>  $sessions
     */
    private function cancelSession(
        Connection $connection,
        array &$sessions,
    ): bool {
        $key = spl_object_id($connection);
        $context = $sessions[$key] ?? null;

        if ($context === null) {
            return false;
        }

        $isPublicDemo =
            $context['public_demo']
            instanceof PublicDemoWebSocketSession;

        try {
            $context['session']->cancel();
        } finally {
            $this->cleanupSession(
                connection: $connection,
                sessions: $sessions,
            );
        }

        return $isPublicDemo;
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
