<?php

use App\Services\Conversation\ConversationStore;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use WebSocket\Client;
use WebSocket\Message\Binary;

it('relays correlated Stop and playback acknowledgements over real WebSockets without sharing clocks', function (): void {
    $path = sys_get_temp_dir().'/vt22-timing-'.bin2hex(random_bytes(8));
    mkdir($path, 0700, true);
    config(['cache.default' => 'vt22_timing', 'cache.stores.vt22_timing' => ['driver' => 'file', 'path' => $path, 'lock_path' => $path]]);
    $store = app(ConversationStore::class);
    $host = $store->create();
    $guest = $store->join($host['code']);
    $process = new Process([PHP_BINARY, base_path('tests/Support/Conversation/serve-websocket.php'), '18092', $path, 'async', 'timing'], base_path());
    $clients = [];
    try {
        $process->start();
        $ready = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @fsockopen('127.0.0.1', 18092, $errno, $error, .05);
            if (is_resource($probe)) {
                fclose($probe);
                $ready = true;
                break;
            }
            usleep(10000);
        }
        expect($ready)->toBeTrue();
        $send = static fn (Client $client, array $data) => $client->text(json_encode($data, JSON_THROW_ON_ERROR));
        $read = static fn (Client $client): array => json_decode($client->receive()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $owner = new Client('ws://127.0.0.1:18092');
        $peer = new Client('ws://127.0.0.1:18092');
        $clients = [$owner, $peer];
        foreach ([[$owner, $host], [$peer, $guest]] as [$client, $credentials]) {
            $client->setTimeout(5);
            $send($client, ['type' => 'conversation_join', ...$credentials]);
            expect($read($client)['type'])->toBe('conversation_joined');
        }
        $read($owner); // peer joined
        $send($owner, ['type' => 'conversation_start', 'mime_type' => 'audio/webm']);
        $turn = $read($owner)['conversation_turn_id'];
        expect($read($peer)['conversation_turn_id'])->toBe($turn);
        $owner->binary('test-audio');
        $send($owner, ['type' => 'conversation_stop']);
        expect($read($peer))->toMatchArray(['type' => 'conversation_stop_received', 'conversation_turn_id' => $turn]);
        $read($owner);
        $read($owner); // transcript, recognition completed
        $translation = $read($peer);
        $tts = $read($peer);
        $correlation = array_intersect_key($tts, array_flip(['conversation_turn_id', 'segment_id', 'segment_index']));
        expect($tts['segment_id'])->toBe($translation['segment_id'])
            ->and($peer->receive())->toBeInstanceOf(Binary::class);
        foreach (['first_pcm_received', 'playback_scheduled', 'playback_started'] as $stage) {
            $send($peer, ['type' => 'conversation_latency_feedback', ...$correlation, 'stage' => $stage, 'after_stop' => true]);
        }
        $read($owner); // translation
        foreach (['first_pcm_received', 'playback_scheduled', 'playback_started'] as $stage) {
            expect($read($owner))->toMatchArray(['type' => 'conversation_playback_ack', ...$correlation, 'stage' => $stage, 'after_stop' => true]);
        }
        expect($peer->receive())->toBeInstanceOf(Binary::class);
        $complete = $read($peer);
        expect($complete['server_latency'])->toHaveKeys(['queue_wait_ms', 'stt_completion_ms', 'stop_to_first_pcm_send_ms', 'socket_write_ms']);
        expect(json_encode($complete))->not->toContain($host['token'])->not->toContain($guest['token']);
    } finally {
        foreach ($clients as $client) {
            $client->disconnect();
        }
        $process->stop(.1);
        File::deleteDirectory($path);
    }
});
