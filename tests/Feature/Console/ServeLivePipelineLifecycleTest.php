<?php

use App\Console\Commands\ServeLivePipelineCommand;
use App\Contracts\CancellableStreamingSpeechToTextProvider;
use App\DTO\StreamingTranscriptionResult;
use App\Enums\Language;
use App\Services\Live\LiveSpeechRecognitionSession;
use App\Services\Live\SpeechCancellationToken;
use Tests\Fakes\FakeStreamingSpeechToTextProvider;
use WebSocket\Connection;

function lifecycleConnection(): Connection
{
    return (new ReflectionClass(Connection::class))
        ->newInstanceWithoutConstructor();
}

function lifecycleSession(): LiveSpeechRecognitionSession
{
    $session = new LiveSpeechRecognitionSession(
        speechToText: new FakeStreamingSpeechToTextProvider,
        mimeType: 'audio/webm',
        sourceLanguage: Language::English,
        onTranscript: static function (string $text, bool $isFinal): void {
            //
        },
    );

    $session->start();

    return $session;
}

function invokeCancelSession(
    Connection $connection,
    array &$sessions,
): bool {
    $command = app(ServeLivePipelineCommand::class);

    $method = new ReflectionMethod(
        ServeLivePipelineCommand::class,
        'cancelSession',
    );

    $arguments = [
        $connection,
        &$sessions,
    ];

    return $method->invokeArgs(
        $command,
        $arguments,
    );
}

it('cancels active speech recognition on disconnect', function () {
    $connection = lifecycleConnection();
    $session = lifecycleSession();

    $sessions = [
        spl_object_id($connection) => [
            'session' => $session,
            'public_demo' => null,
        ],
    ];

    expect($session->isRunning())->toBeTrue();

    $wasPublicDemo = invokeCancelSession($connection, $sessions);

    expect($wasPublicDemo)->toBeFalse()
        ->and($session->isCancelled())->toBeTrue()
        ->and($session->isRunning())->toBeFalse()
        ->and($sessions)->toBeEmpty();
});

it('does not cancel another active connection', function () {
    $oldConnection = lifecycleConnection();
    $newConnection = lifecycleConnection();

    $oldSession = lifecycleSession();
    $newSession = lifecycleSession();

    $sessions = [
        spl_object_id($oldConnection) => [
            'session' => $oldSession,
            'public_demo' => null,
        ],
        spl_object_id($newConnection) => [
            'session' => $newSession,
            'public_demo' => null,
        ],
    ];

    invokeCancelSession($oldConnection, $sessions);

    expect($oldSession->isCancelled())->toBeTrue()
        ->and($newSession->isCancelled())->toBeFalse()
        ->and($newSession->isRunning())->toBeTrue()
        ->and($sessions)->toHaveKey(
            spl_object_id($newConnection),
        );

    invokeCancelSession($newConnection, $sessions);
});

it('removes the session even when cancellation fails', function () {
    $connection = lifecycleConnection();

    $provider = new class implements CancellableStreamingSpeechToTextProvider
    {
        public function transcribe(
            iterable $audioChunks,
            string $mimeType,
            Language $sourceLanguage,
            Closure $onTranscript,
        ): StreamingTranscriptionResult {
            throw new LogicException('Unexpected transcribe call.');
        }

        public function transcribeCancellable(
            iterable $audioChunks,
            string $mimeType,
            Language $sourceLanguage,
            Closure $onTranscript,
            SpeechCancellationToken $cancellationToken,
        ): StreamingTranscriptionResult {
            $cancellationToken->onCancel(
                static function (): void {
                    throw new RuntimeException('RPC cancellation failed.');
                },
            );

            foreach ($audioChunks as $chunk) {
                //
            }

            return new StreamingTranscriptionResult(
                text: 'Ignored',
            );
        }
    };

    $session = new LiveSpeechRecognitionSession(
        speechToText: $provider,
        mimeType: 'audio/webm',
        sourceLanguage: Language::English,
        onTranscript: static function (string $text, bool $isFinal): void {
            //
        },
    );

    $session->start();

    $sessions = [
        spl_object_id($connection) => [
            'session' => $session,
            'public_demo' => null,
        ],
    ];

    expect(
        function () use ($connection, &$sessions): void {
            invokeCancelSession($connection, $sessions);
        },
    )->toThrow(RuntimeException::class, 'RPC cancellation failed.');

    expect($session->isCancelled())->toBeTrue()
        ->and($sessions)->toBeEmpty();
});
