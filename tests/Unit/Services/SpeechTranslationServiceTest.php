<?php

use App\Enums\Language;
use App\Services\SpeechTranslationService;
use Tests\Fakes\FakeSpeechToTextProvider;
use Tests\Fakes\FakeTextToSpeechProvider;
use Tests\Fakes\FakeTranslationProvider;

test('it translates speech through provider contracts', function () {
    $speechToText = new FakeSpeechToTextProvider('I need a truck');

    $translator = new FakeTranslationProvider(
        'Мне нужен грузовик',
    );

    $textToSpeech = new FakeTextToSpeechProvider(
        audio: 'translated-audio',
        mimeType: 'audio/mpeg',
    );

    $service = new SpeechTranslationService(
        speechToText: $speechToText,
        translator: $translator,
        textToSpeech: $textToSpeech,
    );

    $result = $service->translate(
        audio: 'source-audio',
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
