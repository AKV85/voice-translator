<?php

use Symfony\Component\Process\Process;

it('rejects invalid Artisan worker input without calling APIs', function (): void {
    $process = new Process([
        PHP_BINARY,
        base_path('artisan'),
        'conversation:run-worker',
    ], base_path());

    $process->setInput(json_encode([
        'turn_id' => 'test-001',
        'source_text' => 'Привет!',
    ], JSON_THROW_ON_ERROR));

    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())
        ->toContain('Invalid worker job.')
        ->and($process->getOutput())->toBe('');
});
