<?php

use App\Enums\Language;
use App\Services\Live\LiveSpeechRecognitionSession;
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
