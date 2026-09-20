<?php

use App\DTO\AudioInput;
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

    $audio = new AudioInput(
        content: 'source-audio',
        mimeType: 'audio/webm',
    );

    $result = $service->translate(
        audio: $audio,
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

    expect($speechToText->receivedAudio?->content)
        ->toBe('source-audio')
        ->and($speechToText->receivedAudio?->mimeType)
        ->toBe('audio/webm')
        ->and($speechToText->receivedLanguage)
        ->toBe(Language::English)

        ->and($translator->receivedText)
        ->toBe('I need a truck')
        ->and($translator->receivedSourceLanguage)
        ->toBe(Language::English)
        ->and($translator->receivedTargetLanguage)
        ->toBe(Language::Russian)

        ->and($textToSpeech->receivedText)
        ->toBe('Мне нужен грузовик')
        ->and($textToSpeech->receivedLanguage)
        ->toBe(Language::Russian);
});
