<?php

use App\Services\Conversation\ConversationWorkerManager;

it('reports an Artisan worker validation failure through the manager', function (): void {
    $manager = new ConversationWorkerManager(
        workerCommand: [
            PHP_BINARY,
            base_path('artisan'),
            'conversation:run-worker',
        ],
        timeoutSeconds: 20,
    );

    $turnId = bin2hex(random_bytes(8));

    try {
        $manager->startJob($turnId, [
            'turn_id' => $turnId,
            'source_text' => 'Привет!',
        ]);

        $failure = null;
        $deadline = microtime(true) + 15;

        while (microtime(true) < $deadline) {
            try {
                $result = $manager->poll($turnId);

                if ($result !== null) {
                    throw new LogicException(
                        'Unexpected successful worker result.',
                    );
                }
            } catch (RuntimeException $exception) {
                $failure = $exception;

                break;
            }

            usleep(10000);
        }

        expect($failure)->toBeInstanceOf(RuntimeException::class)
            ->and($failure->getMessage())->toContain('Invalid worker job.')
            ->and($manager->has($turnId))->toBeFalse();
    } finally {
        $manager->cancel($turnId);
    }
});

it('rejects an invalid conversation job before starting a process', function (): void {
    $manager = new ConversationWorkerManager(
        workerCommand: [
            PHP_BINARY,
            base_path('artisan'),
            'conversation:run-worker',
        ],
    );

    $turnId = bin2hex(random_bytes(8));

    expect(
        fn () => $manager->startJob($turnId, [
            'turn_id' => 'another-turn',
            'source_text' => 'Привет!',
        ]),
    )->toThrow(
        LogicException::class,
        'Invalid conversation worker job.',
    );

    expect($manager->has($turnId))->toBeFalse();
});
