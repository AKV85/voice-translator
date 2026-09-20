<?php

use App\DTO\SpeechSynthesisResult;

test('it stores synthesized audio and mime type', function () {
    $result = new SpeechSynthesisResult(
        audio: 'fake-audio',
        mimeType: 'audio/mpeg',
    );

    expect($result->audio)->toBe('fake-audio')
        ->and($result->mimeType)->toBe('audio/mpeg');
});
