<?php

use App\Contracts\SpeechToTextProvider;
use App\Contracts\TextToSpeechProvider;
use App\Contracts\TranslationProvider;
use App\DTO\AudioInput;
use App\Enums\Language;
use App\Services\SpeechTranslationService;
use Tests\Fakes\FakeSpeechToTextProvider;
use Tests\Fakes\FakeTextToSpeechProvider;
use Tests\Fakes\FakeTranslationProvider;

test('speech translation service can be resolved through provider contracts', function () {
    $this->app->bind(
        SpeechToTextProvider::class,
        fn () => new FakeSpeechToTextProvider('I need a truck'),
    );

    $this->app->bind(
        TranslationProvider::class,
        fn () => new FakeTranslationProvider('Мне нужен грузовик'),
    );

    $this->app->bind(
        TextToSpeechProvider::class,
        fn () => new FakeTextToSpeechProvider(
            audio: 'translated-audio',
            mimeType: 'audio/mpeg',
        ),
    );

    $service = $this->app->make(SpeechTranslationService::class);

    expect($service)
        ->toBeInstanceOf(SpeechTranslationService::class);

    $result = $service->translate(
        audio: new AudioInput(
            content: 'source-audio',
            mimeType: 'audio/webm',
        ),
        sourceLanguage: Language::English,
        targetLanguage: Language::Russian,
    );

    expect($result->transcription->text)
        ->toBe('I need a truck')
        ->and($result->translation->text)
        ->toBe('Мне нужен грузовик')
        ->and($result->speech->audio)
        ->toBe('translated-audio')
        ->and($result->speech->mimeType)
        ->toBe('audio/mpeg');
});
