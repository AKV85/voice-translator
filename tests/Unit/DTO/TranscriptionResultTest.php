<?php

use App\DTO\TranscriptionResult;

test('it stores transcription text', function () {
    $result = new TranscriptionResult('I need a truck');

    expect($result->text)->toBe('I need a truck');
});
