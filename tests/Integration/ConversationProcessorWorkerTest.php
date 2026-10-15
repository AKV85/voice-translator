<?php

use App\Services\Conversation\ConversationWorkerManager;

it('runs the conversation processor inside a worker process', function (): void {
    $manager = new ConversationWorkerManager(
        workerCommand: [
            PHP_BINARY,
            base_path('tests/Support/Conversation/processor-worker.php'),
        ],
        timeoutSeconds: 15,
    );

    $turnId = bin2hex(random_bytes(8));

    try {
        $manager->startJob($turnId, [
            'turn_id' => $turnId,
            'source_text' => 'Привет!',
            'source_language' => 'ru',
            'target_language' => 'en',
        ]);

        $result = null;
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            $result = $manager->poll($turnId);

            if ($result !== null) {
                break;
            }

            usleep(10000);
        }

        expect($result)->not->toBeNull();

        expect($result['turn_id'])->toBe($turnId)
            ->and($result['status'])->toBe('completed')
            ->and($result['translated_text'])
            ->toBe('Hello from the worker.')
            ->and($result['mime_type'])->toBe('audio/pcm')
            ->and(base64_decode($result['audio_base64'], true))
            ->toBe("\x01\x02\x03\x04")
            ->and($manager->has($turnId))->toBeFalse();
    } finally {
        $manager->cancel($turnId);
    }
});
