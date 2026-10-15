<?php

use App\Services\Conversation\ConversationWorkerManager;

it('polls a conversation worker without blocking', function (): void {
    $manager = new ConversationWorkerManager(
        workerPath: base_path('tests/Support/Conversation/pipeline-worker.php'),
    );
    $turnId = bin2hex(random_bytes(8));

    try {
        $manager->start($turnId, 1000);

        expect($manager->has($turnId))->toBeTrue()
            ->and($manager->poll($turnId))->toBeNull();

        $result = null;
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $result = $manager->poll($turnId);

            if ($result !== null) {
                break;
            }

            usleep(20000);
        }

        expect($result)->not->toBeNull()
            ->and($result['turn_id'])->toBe($turnId)
            ->and($result['status'])->toBe('completed')
            ->and($manager->has($turnId))->toBeFalse();
    } finally {
        $manager->cancel($turnId);
    }
});

it('cancels a running conversation worker', function (): void {
    $manager = new ConversationWorkerManager(
        workerPath: base_path('tests/Support/Conversation/pipeline-worker.php'),
    );
    $turnId = bin2hex(random_bytes(8));

    $manager->start($turnId, 5000);

    expect($manager->has($turnId))->toBeTrue();

    $manager->cancel($turnId);

    expect($manager->has($turnId))->toBeFalse()
        ->and($manager->poll($turnId))->toBeNull();
});

it('rejects duplicate conversation worker turns', function (): void {
    $manager = new ConversationWorkerManager(
        workerPath: base_path('tests/Support/Conversation/pipeline-worker.php'),
    );
    $turnId = bin2hex(random_bytes(8));

    try {
        $manager->start($turnId, 1000);

        expect(
            fn () => $manager->start($turnId, 1000),
        )->toThrow(LogicException::class);
    } finally {
        $manager->cancel($turnId);
    }
});

it('limits concurrent conversation workers', function (): void {
    $manager = new ConversationWorkerManager(
        workerPath: base_path('tests/Support/Conversation/pipeline-worker.php'),
    );
    $turnIds = [];

    try {
        for ($i = 0; $i < 4; $i++) {
            $turnId = bin2hex(random_bytes(8));

            $manager->start($turnId, 5000);
            $turnIds[] = $turnId;
        }

        expect(
            fn () => $manager->start(
                bin2hex(random_bytes(8)),
                5000,
            ),
        )->toThrow(
            RuntimeException::class,
            'Conversation worker capacity reached.',
        );

        $manager->cancel($turnIds[0]);

        $replacementId = bin2hex(random_bytes(8));
        $manager->start($replacementId, 1000);
        $turnIds[] = $replacementId;

        expect($manager->has($replacementId))->toBeTrue();
    } finally {
        foreach ($turnIds as $turnId) {
            $manager->cancel($turnId);
        }
    }
});

it('terminates timed out conversation workers', function (): void {
    $manager = new ConversationWorkerManager(
        workerPath: base_path('tests/Support/Conversation/pipeline-worker.php'),
        timeoutSeconds: 0.2,
    );

    $turnId = bin2hex(random_bytes(8));

    try {
        $manager->start($turnId, 5000);

        usleep(400000);

        expect(
            fn () => $manager->poll($turnId),
        )->toThrow(
            RuntimeException::class,
            'Conversation worker timed out.',
        );

        expect($manager->has($turnId))->toBeFalse();

        // Capacity is released after timeout.
        $nextTurn = bin2hex(random_bytes(8));

        $manager->start($nextTurn, 0);

        expect($manager->has($nextTurn))->toBeTrue();

        $manager->cancel($nextTurn);
    } finally {
        $manager->cancel($turnId);
    }
});

it('rejects an unavailable conversation worker script', function (): void {
    expect(
        fn () => new ConversationWorkerManager(
            workerPath: base_path('missing-conversation-worker.php'),
        ),
    )->toThrow(
        LogicException::class,
        'Conversation worker script is unavailable.',
    );
});
