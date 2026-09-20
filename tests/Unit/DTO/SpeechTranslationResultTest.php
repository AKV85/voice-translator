<?php

use App\DTO\SpeechSynthesisResult;
use App\DTO\SpeechTranslationResult;
use App\DTO\TranscriptionResult;
use App\DTO\TranslationResult;

test('it stores complete speech translation result', function () {
    $result = new SpeechTranslationResult(
        transcription: new TranscriptionResult('I need a truck'),
        translation: new TranslationResult('Мне нужен грузовик'),
        speech: new SpeechSynthesisResult(
            audio: 'fake-audio',
            mimeType: 'audio/mpeg',
        ),
    );

    expect($result->transcription->text)->toBe('I need a truck')
        ->and($result->translation->text)->toBe('Мне нужен грузовик')
        ->and($result->speech->audio)->toBe('fake-audio')
        ->and($result->speech->mimeType)->toBe('audio/mpeg');
});
