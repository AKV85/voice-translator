<?php

use App\Services\Conversation\ConversationWorkerManager;

it('delivers ordered audio and preserves correlated latency once before cleaning up', function (): void {
    $manager = new ConversationWorkerManager([
        PHP_BINARY,
        base_path('tests/Support/Conversation/stability-worker.php'),
    ]);

    try {
        $manager->startJob('turn-1', ['turn_id' => 'turn-1', 'source_text' => 'Mock speech', 'stream' => true]);
        $events = [];
        $deadline = microtime(true) + 5;

        while ($manager->has('turn-1') && microtime(true) < $deadline) {
            array_push($events, ...$manager->pollStream('turn-1'));
            usleep(1000);
        }

        expect(array_column($events, 'type'))->toBe([
            'translation', 'tts_started', 'audio_chunk', 'audio_chunk', 'completed',
        ])->and(array_column($events, 'turn_id'))->toBe(array_fill(0, 5, 'turn-1'))
            ->and($events[2]['audio'].$events[3]['audio'])->toBe("\x01\x02\x03\x04")
            ->and($events[4]['worker_latency'])->toBe([
                'startup_ms' => null,
                'translation_ms' => 12,
                'translation_to_first_pcm_ms' => 34,
                'total_ms' => 56,
            ])->and($manager->has('turn-1'))->toBeFalse()
            ->and($manager->pollStream('turn-1'))->toBeEmpty();

        // Reusing the ID must not replay audio or completion metadata.
        $manager->startJob('turn-1', [
            'turn_id' => 'turn-1',
            'source_text' => 'Mock speech',
            'stream' => true,
            'omit_latency' => true,
        ]);
        $nextEvents = [];
        $deadline = microtime(true) + 5;
        while ($manager->has('turn-1') && microtime(true) < $deadline) {
            array_push($nextEvents, ...$manager->pollStream('turn-1'));
            usleep(1000);
        }

        expect(array_column($nextEvents, 'type'))->toBe(array_column($events, 'type'))
            ->and($nextEvents[4]['worker_latency'])->toBeEmpty()
            ->and($manager->has('turn-1'))->toBeFalse();
    } finally {
        $manager->cancel('turn-1');
    }
});

it('cleans up invalid or failed streams without publishing completion', function (string $mode): void {
    $manager = new ConversationWorkerManager([
        PHP_BINARY,
        base_path('tests/Support/Conversation/stability-worker.php'),
        $mode,
    ]);
    $events = [];

    try {
        $manager->startJob('turn-1', ['turn_id' => 'turn-1', 'source_text' => 'Mock speech', 'stream' => true]);

        expect(function () use ($manager, &$events): void {
            $deadline = microtime(true) + 5;
            while ($manager->has('turn-1') && microtime(true) < $deadline) {
                array_push($events, ...$manager->pollStream('turn-1'));
                usleep(1000);
            }
        })->toThrow(RuntimeException::class);

        expect(array_column($events, 'type'))->not->toContain('completed')
            ->and($manager->has('turn-1'))->toBeFalse()
            ->and($manager->pollStream('turn-1'))->toBeEmpty();

        $manager->startJob('turn-1', ['turn_id' => 'turn-1', 'source_text' => 'Mock speech', 'stream' => true]);
        expect($manager->has('turn-1'))->toBeTrue();
    } finally {
        $manager->cancel('turn-1');
    }
})->with(['out_of_order', 'duplicate_completion', 'wrong_size', 'stale_turn', 'failed_exit']);
