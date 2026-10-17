<?php

use App\Services\Conversation\ConversationWorkerManager;

it('runs real translation and TTS in a background worker', function (): void {
    if (getenv('CONVERSATION_LIVE_WORKER_TEST') !== '1') {
        test()->markTestSkipped('Live worker test is opt-in.');
    }

    $manager = new ConversationWorkerManager(
        workerCommand: [
            PHP_BINARY,
            base_path('artisan'),
            'conversation:run-worker',
        ],
        timeoutSeconds: 60,
    );

    $turnId = bin2hex(random_bytes(8));

    try {
        $startedAt = microtime(true);

        $manager->startJob($turnId, [
            'turn_id' => $turnId,
            'source_text' => 'Привет! Мне нужен грузовик.',
            'source_language' => 'ru',
            'target_language' => 'en',
        ]);

        $launchSeconds = microtime(true) - $startedAt;

        expect($launchSeconds)->toBeLessThan(2)
            ->and($manager->has($turnId))->toBeTrue();

        $result = null;
        $deadline = microtime(true) + 55;

        while (microtime(true) < $deadline) {
            $result = $manager->pollAudio($turnId);

            if ($result !== null) {
                break;
            }

            usleep(20000);
        }

        expect($result)->not->toBeNull();

        expect($result['turn_id'])->toBe($turnId)
            ->and($result['status'])->toBe('completed')
            ->and($result['translated_text'])->not->toBeEmpty()
            ->and($result['mime_type'])->toBe('audio/pcm')
            ->and(strlen($result['audio']))->toBeGreaterThan(0)
            ->and(strlen($result['audio']) % 2)->toBe(0)
            ->and($manager->has($turnId))->toBeFalse();

        fwrite(STDERR, PHP_EOL.sprintf(
            'Live worker: %.2f s, translation: %s, audio: %d bytes'.PHP_EOL,
            microtime(true) - $startedAt,
            $result['translated_text'],
            strlen($result['audio']),
        ));
    } finally {
        $manager->cancel($turnId);
    }
});
