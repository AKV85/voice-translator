<?php

use App\DTO\AudioInput;

test('it stores audio content and mime type', function () {
    $audio = new AudioInput(
        content: 'fake-audio',
        mimeType: 'audio/webm',
    );

    expect($audio->content)->toBe('fake-audio')
        ->and($audio->mimeType)->toBe('audio/webm');
});
