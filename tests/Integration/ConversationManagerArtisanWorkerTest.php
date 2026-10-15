<?php

use App\Services\Conversation\ConversationWorkerManager;

it('runs an Artisan conversation worker through the manager', function (): void {
    $manager = new ConversationWorkerManager(
        workerCommand: [
            PHP_BINARY,
            base_path('artisan'),
            'conversation:run-worker',
        ],
    );

    $turnId = bin2hex(random_bytes(8));

    try {
        $manager->startJob($turnId, [
            'turn_id' => $turnId,
            'source_text' => 'Привет!',
        ]);

        expect($manager->has($turnId))->toBeTrue();

        $result = null;
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $result = $manager->poll($turnId);

            if ($result !== null) {
                break;
            }

            usleep(10000);
        }

        expect($result)->not->toBeNull()
            ->and($result['turn_id'])->toBe($turnId)
            ->and($result['status'])->toBe('completed')
            ->and($result['translated_text'])->toBe('Привет!')
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
