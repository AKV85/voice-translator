<?php

use App\Contracts\CancellableStreamingSpeechToTextProvider;
use App\DTO\StreamingTranscriptionResult;
use App\Enums\Language;
use App\Services\Live\LiveSpeechRecognitionSession;
use App\Services\Live\SpeechCancellationToken;
use LogicException;
use RuntimeException;
use Tests\Fakes\FakeStreamingSpeechToTextProvider;

it('streams live audio to the speech provider and returns the final transcript', function () {
    $provider = new FakeStreamingSpeechToTextProvider(
        transcription: 'Привет мир',
    );

    $events = [];

    $session = new LiveSpeechRecognitionSession(
        speechToText: $provider,
        mimeType: 'audio/webm;codecs=opus',
        sourceLanguage: Language::Russian,
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

    $session->start();

    expect($session->isRunning())
        ->toBeTrue()
        ->and($provider->receivedMimeType)
        ->toBe('audio/webm;codecs=opus')
        ->and($provider->receivedLanguage)
        ->toBe(Language::Russian)
        ->and($provider->receivedChunks)
        ->toBe([]);

    $session->pushAudio(
        'first-chunk',
    );

    expect($provider->receivedChunks)
        ->toBe([
            'first-chunk',
        ]);

    $session->pushAudio(
        'second-chunk',
    );

    expect($provider->receivedChunks)
        ->toBe([
            'first-chunk',
            'second-chunk',
        ]);

    $result = $session->finish();

    expect($session->isRunning())
        ->toBeFalse()
        ->and($result->text)
        ->toBe('Привет мир')
        ->and($events)
        ->toBe([
            [
                'text' => 'Привет мир',
                'is_final' => true,
            ],
        ]);
});

it('rejects audio before the session has started', function () {
    $session = new LiveSpeechRecognitionSession(
        speechToText: new FakeStreamingSpeechToTextProvider,
        mimeType: 'audio/webm',
        sourceLanguage: Language::English,
        onTranscript: static function (
            string $text,
            bool $isFinal,
        ): void {
            //
        },
    );

    expect(
        fn () => $session->pushAudio('audio'),
    )->toThrow(
        LogicException::class,
        'Live speech recognition session has not started.',
    );
});

it('cannot be started more than once', function () {
    $session = new LiveSpeechRecognitionSession(
        speechToText: new FakeStreamingSpeechToTextProvider,
        mimeType: 'audio/webm',
        sourceLanguage: Language::English,
        onTranscript: static function (
            string $text,
            bool $isFinal,
        ): void {
            //
        },
    );

    $session->start();

    expect(
        fn () => $session->start(),
    )->toThrow(
        LogicException::class,
        'Live speech recognition session has already started.',
    );

    $session->finish();
});

it('propagates speech provider failures', function () {
    $providerException = new RuntimeException(
        'Provider failed.',
    );

    $session = new LiveSpeechRecognitionSession(
        speechToText: new FakeStreamingSpeechToTextProvider(
            exception: $providerException,
        ),
        mimeType: 'audio/webm',
        sourceLanguage: Language::English,
        onTranscript: static function (
            string $text,
            bool $isFinal,
        ): void {
            //
        },
    );

    expect(
        fn () => $session->start(),
    )->toThrow(
        RuntimeException::class,
        'Provider failed.',
    );
});

it('prevents further audio after cancellation', function () {
    $provider = new FakeStreamingSpeechToTextProvider(
        transcription: 'Ignored result',
    );

    $events = [];

    $session = new LiveSpeechRecognitionSession(
        speechToText: $provider,
        mimeType: 'audio/webm',
        sourceLanguage: Language::Russian,
        onTranscript: function (string $text, bool $isFinal) use (&$events): void {
            $events[] = [$text, $isFinal];
        },
    );

    $session->start();
    $session->cancel();
    $session->cancel();

    expect($session->isCancelled())->toBeTrue()
        ->and($session->isRunning())->toBeFalse()
        ->and($events)->toBe([]);

    expect(fn () => $session->pushAudio('late-audio'))
        ->toThrow(LogicException::class, 'cancelled');

    expect(fn () => $session->finish())
        ->toThrow(LogicException::class, 'cancelled');
});

it('suppresses provider callbacks after cancellation', function () {
    $provider = new FakeStreamingSpeechToTextProvider(
        transcription: 'This must not be delivered',
    );

    $received = [];

    $session = new LiveSpeechRecognitionSession(
        speechToText: $provider,
        mimeType: 'audio/webm',
        sourceLanguage: Language::English,
        onTranscript: function (
            string $text,
            bool $isFinal,
        ) use (&$received): void {
            $received[] = $text;
        },
    );

    $session->start();

    expect($session->isRunning())->toBeTrue();

    $session->cancel();

    expect($session->isCancelled())->toBeTrue()
        ->and($session->isRunning())->toBeFalse()
        ->and($received)->toBeEmpty()
        ->and($provider->receivedChunks)->toBeEmpty();
});

it('cancels the provider before releasing the audio fiber', function () {
    $events = [];

    $provider = new class($events) implements CancellableStreamingSpeechToTextProvider
    {
        public function __construct(private array &$events) {}

        public function transcribe(
            iterable $audioChunks,
            string $mimeType,
            Language $sourceLanguage,
            Closure $onTranscript,
        ): StreamingTranscriptionResult {
            throw new LogicException('Cancellable method was not used.');
        }

        public function transcribeCancellable(
            iterable $audioChunks,
            string $mimeType,
            Language $sourceLanguage,
            Closure $onTranscript,
            SpeechCancellationToken $cancellationToken,
        ): StreamingTranscriptionResult {
            $cancellationToken->onCancel(function (): void {
                $this->events[] = 'rpc_cancelled';
            });

            foreach ($audioChunks as $chunk) {
                $this->events[] = 'audio_received';
            }

            $this->events[] = 'fiber_released';

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
    $session->cancel();

    expect($events)->toBe([
        'rpc_cancelled',
        'fiber_released',
    ]);
});

it('does not return a transcript after cancellation', function () {
    $provider = new FakeStreamingSpeechToTextProvider(
        transcription: 'This should never be translated',
    );

    $transcripts = [];

    $session = new LiveSpeechRecognitionSession(
        speechToText: $provider,
        mimeType: 'audio/webm',
        sourceLanguage: Language::English,
        onTranscript: function (
            string $text,
            bool $isFinal,
        ) use (&$transcripts): void {
            $transcripts[] = $text;
        },
    );

    $session->start();
    $session->cancel();

    expect(
        fn () => $session->finish(),
    )->toThrow(
        LogicException::class,
        'Live speech recognition session was cancelled.',
    );

    expect($transcripts)->toBeEmpty();
});
