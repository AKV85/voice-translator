<?php

use Symfony\Component\Process\Process;

it('exchanges JSON with the Artisan conversation worker', function (): void {
    $process = new Process([
        PHP_BINARY,
        base_path('artisan'),
        'conversation:run-worker',
    ], base_path());

    $process->setInput(json_encode([
        'turn_id' => 'test-artisan-001',
        'source_text' => 'Привет!',
    ], JSON_THROW_ON_ERROR));

    $process->mustRun();

    $result = json_decode(
        $process->getOutput(),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($result['turn_id'])->toBe('test-artisan-001')
        ->and($result['status'])->toBe('completed')
        ->and($result['translated_text'])->toBe('Привет!')
        ->and(base64_decode($result['audio_base64'], true))->toBe('')
        ->and($result['mime_type'])->toBe('audio/pcm');
});
