<?php

use Symfony\Component\Process\Process;

it('runs a conversation worker without blocking the caller', function (): void {
    $turnId = bin2hex(random_bytes(8));

    $process = new Process([
        PHP_BINARY,
        base_path('tests/Support/Conversation/pipeline-worker.php'),
    ]);

    $process->setInput(json_encode([
        'turn_id' => $turnId,
        'delay_ms' => 1500,
    ], JSON_THROW_ON_ERROR));

    $startedAt = hrtime(true);

    $process->start();

    $startElapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

    try {
        expect($startElapsedMs)->toBeLessThan(1000)
            ->and($process->isRunning())->toBeTrue();

        // The main process can do other work.
        $mainProcessActivity = 'still-responsive';

        expect($mainProcessActivity)->toBe('still-responsive');

        $process->wait();

        expect($process->isSuccessful())->toBeTrue();

        $result = json_decode(
            $process->getOutput(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($result['turn_id'])->toBe($turnId)
            ->and($result['status'])->toBe('completed')
            ->and($result['translated_text'])
            ->toBe('Worker completed translation.');
    } finally {
        if ($process->isRunning()) {
            $process->stop(0.1);
        }
    }
});
