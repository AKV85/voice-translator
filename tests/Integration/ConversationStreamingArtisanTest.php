<?php

use App\Services\Conversation\ConversationWorkerManager;

it('streams aligned unchanged PCM from persistent Artisan jobs with correlated monotonic stage timings', function (int $initial): void {
    $manager = new ConversationWorkerManager([PHP_BINARY, base_path('tests/Support/Conversation/streaming-artisan-worker.php'), (string) $initial], 10, true);
    try {
        foreach ([1, 2] as $index) {
            $id = bin2hex(random_bytes(16));
            $manager->startJob($id, [
                'turn_id' => $id, 'source_text' => 'Hello', 'source_language' => 'en', 'target_language' => 'ru',
                'stream' => true, 'server_worker_requested_ns' => hrtime(true), 'server_latency' => ['queue_wait_ms' => 5.5],
            ]);
            $events = [];
            $deadline = hrtime(true) + 10_000_000_000;
            while ($manager->has($id) && hrtime(true) < $deadline) {
                array_push($events, ...$manager->pollStream($id));
                usleep(1000);
            }
            expect($manager->has($id))->toBeFalse()
                ->and($events[0]['type'])->toBe('translation')
                ->and($events[1]['type'])->toBe('tts_started')
                ->and(array_column($events, 'turn_id'))->each->toBe($id);
            $audio = array_values(array_filter($events, fn ($event) => $event['type'] === 'audio_chunk'));
            expect(strlen($audio[0]['audio']))->toBe($initial)
                ->and(implode('', array_column($audio, 'audio')))->toBe(str_repeat("\x01\x02", 3840));
            foreach (array_slice($audio, 1, -1) as $packet) {
                expect(strlen($packet['audio']))->toBe(3840);
            }
            $complete = $events[array_key_last($events)];
            expect($complete['type'])->toBe('completed')
                ->and($complete['worker_latency'])->toHaveKeys(['startup_ms', 'queue_wait_ms', 'translation_ms', 'tts_request_ms', 'tts_request_to_headers_ms', 'tts_request_to_first_audio_ms', 'first_pcm_emitted_ms', 'first_audio_to_pcm_emit_ms'])
                ->and($complete['worker_latency']['queue_wait_ms'])->toBe(5.5)
                ->and($complete['worker_latency']['first_pcm_emitted_ms'])->toBeGreaterThanOrEqual($complete['worker_latency']['tts_request_ms']);
        }
    } finally {
        if (isset($id)) {
            $manager->cancel($id);
        }
        unset($manager); // Pool destructor must release both persistent workers.
    }
})->with([1920, 3840]);
