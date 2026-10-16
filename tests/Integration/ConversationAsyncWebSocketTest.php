<?php

use App\Services\Conversation\ConversationStore;
use WebSocket\Client;
use WebSocket\Message\Binary;

it('delivers translated PCM16 through the real async conversation server', function (): void {
    $port = 18083;

    $cachePath = sys_get_temp_dir()
        .'/vt21-async-'.bin2hex(random_bytes(8));

    mkdir($cachePath, 0700, true);

    $originalCache = config('cache.default');

    $process = null;
    $pipes = [];
    $clients = [];

    try {
        config()->set('conversation.enabled', true);
        config()->set('cache.default', 'vt21_async_integration');
        config()->set('cache.stores.vt21_async_integration', [
            'driver' => 'file',
            'path' => $cachePath,
            'lock_path' => $cachePath,
        ]);

        $store = app(ConversationStore::class);

        $host = $store->create();
        $guest = $store->join($host['code']);

        $process = proc_open(
            [
                PHP_BINARY,
                base_path('tests/Support/Conversation/serve-websocket.php'),
                (string) $port,
                $cachePath,
                'async',
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $cachePath.'/server.log', 'a'],
                2 => ['file', $cachePath.'/server.log', 'a'],
            ],
            $pipes,
            base_path(),
        );

        expect($process)->not->toBeFalse();

        fclose($pipes[0]);
        unset($pipes[0]);

        $ready = false;

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $socket = @fsockopen(
                '127.0.0.1',
                $port,
                $errno,
                $error,
                0.1,
            );

            if (is_resource($socket)) {
                fclose($socket);
                $ready = true;

                break;
            }

            if (! proc_get_status($process)['running']) {
                break;
            }

            usleep(100000);
        }

        if (! $ready) {
            throw new RuntimeException(
                'Async WebSocket server did not start: '
                .file_get_contents($cachePath.'/server.log'),
            );
        }

        $connect = function () use ($port, &$clients): Client {
            $client = new Client("ws://127.0.0.1:{$port}");
            $client->setTimeout(8);
            $client->connect();

            $clients[] = $client;

            return $client;
        };

        $send = static function (Client $client, array $payload): void {
            $client->text(json_encode($payload, JSON_THROW_ON_ERROR));
        };

        $receive = static function (Client $client): array {
            return json_decode(
                $client->receive()->getContent(),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        };

        $hostClient = $connect();

        $send($hostClient, [
            'type' => 'conversation_join',
            'code' => $host['code'],
            'token' => $host['token'],
        ]);

        expect($receive($hostClient)['type'])->toBe('conversation_joined');

        $guestClient = $connect();

        $send($guestClient, [
            'type' => 'conversation_join',
            'code' => $guest['code'],
            'token' => $guest['token'],
        ]);

        expect($receive($guestClient)['type'])->toBe('conversation_joined')
            ->and($receive($hostClient)['type'])
            ->toBe('conversation_peer_joined');

        $send($hostClient, [
            'type' => 'conversation_start',
            'mime_type' => 'audio/webm',
        ]);

        expect($receive($hostClient)['type'])->toBe('conversation_turn_started')
            ->and($receive($guestClient)['type'])
            ->toBe('conversation_peer_started');

        $hostClient->binary('host-audio');

        $send($hostClient, [
            'type' => 'conversation_stop',
        ]);

        expect($receive($hostClient)['type'])->toBe('conversation_transcript')
            ->and($receive($hostClient)['type'])
            ->toBe('conversation_recognition_completed');

        // A second stop must not abort the running worker.
        $send($hostClient, [
            'type' => 'conversation_stop',
        ]);

        $duplicate = $receive($hostClient);

        expect($duplicate['type'])->toBe('error')
            ->and($duplicate['message'])
            ->toBe('Conversation translation is already processing.');

        // Ownership remains locked until translation finishes.
        $send($guestClient, [
            'type' => 'conversation_start',
            'mime_type' => 'audio/webm',
        ]);

        expect($receive($guestClient)['type'])
            ->toBe('conversation_turn_busy');

        $translation = $receive($guestClient);

        expect($translation['type'])
            ->toBe('conversation_translation_completed')
            ->and($translation['translated_text'])
            ->toBe('FAKE-TRANSLATION-ru-en:Integration test transcript');

        $tts = $receive($guestClient);

        expect($tts['type'])
            ->toBe('conversation_tts_started')
            ->and($tts['format'])
            ->toBe('pcm16')
            ->and($tts['sample_rate_hz'])
            ->toBe(24000);

        $startedAt = microtime(true);

        $firstChunk = $guestClient->receive();

        expect($firstChunk)->toBeInstanceOf(Binary::class)
            ->and($firstChunk->getContent())
            ->toBe(pack('v*', 0, 1200));

        // The fake worker waits 700 ms before emitting chunk #2.
        $firstChunkAt = microtime(true);

        $secondChunk = $guestClient->receive();

        expect($secondChunk)->toBeInstanceOf(Binary::class)
            ->and($secondChunk->getContent())
            ->toBe(pack('v*', 2400, 1200, 0))
            ->and(microtime(true) - $firstChunkAt)
            ->toBeGreaterThan(0.3);

        $combinedAudio = $firstChunk->getContent()
            .$secondChunk->getContent();

        expect($combinedAudio)
            ->toBe(pack('v*', 0, 1200, 2400, 1200, 0));

        $completed = $receive($guestClient);

        expect($completed['type'])->toBe('conversation_completed')
            ->and($completed['translated_audio_bytes'])->toBe(10);

        expect($receive($guestClient)['type'])
            ->toBe('conversation_peer_stopped');

        $hostTranslation = $receive($hostClient);

        expect($hostTranslation['type'])
            ->toBe('conversation_translation_completed');

        expect($hostTranslation['translated_text'])
            ->toBeString()
            ->not->toBeEmpty();

        expect($receive($hostClient)['type'])
            ->toBe('conversation_turn_stopped');

        // The Guest can now acquire a new turn.
        $send($guestClient, [
            'type' => 'conversation_start',
            'mime_type' => 'audio/webm',
        ]);

        expect($receive($guestClient)['type'])
            ->toBe('conversation_turn_started');

        expect($receive($hostClient)['type'])
            ->toBe('conversation_peer_started');

        // Finish the Guest turn that was started above.
        $guestClient->binary('guest-audio');

        $send($guestClient, [
            'type' => 'conversation_stop',
        ]);

        expect($receive($guestClient)['type'])
            ->toBe('conversation_transcript');

        expect($receive($guestClient)['type'])
            ->toBe('conversation_recognition_completed');

        expect($receive($hostClient)['type'])
            ->toBe('conversation_translation_completed');

        expect($receive($hostClient)['type'])
            ->toBe('conversation_tts_started');

        $guestFirstChunk = $hostClient->receive();

        expect($guestFirstChunk)
            ->toBeInstanceOf(Binary::class)
            ->and($guestFirstChunk->getContent())
            ->toBe(pack('v*', 0, 1200));

        $guestSecondChunk = $hostClient->receive();

        expect($guestSecondChunk)
            ->toBeInstanceOf(Binary::class)
            ->and($guestSecondChunk->getContent())
            ->toBe(pack('v*', 2400, 1200, 0));

        expect(
            $guestFirstChunk->getContent()
            .$guestSecondChunk->getContent()
        )->toBe(pack('v*', 0, 1200, 2400, 1200, 0));

        expect($receive($hostClient)['type'])
            ->toBe('conversation_completed');

        expect($receive($hostClient)['type'])
            ->toBe('conversation_peer_stopped');

        $guestTranslation = $receive($guestClient);

        expect($guestTranslation['type'])
            ->toBe('conversation_translation_completed');

        expect($guestTranslation['translated_text'])
            ->toBeString()
            ->not->toBeEmpty();

        expect($receive($guestClient)['type'])
            ->toBe('conversation_turn_stopped');

        // Guest disconnects while the async worker is running.
        $send($hostClient, [
            'type' => 'conversation_start',
            'mime_type' => 'audio/webm',
        ]);

        expect($receive($hostClient)['type'])
            ->toBe('conversation_turn_started');

        expect($receive($guestClient)['type'])
            ->toBe('conversation_peer_started');

        $hostClient->binary('audio-before-disconnect');

        $send($hostClient, [
            'type' => 'conversation_stop',
        ]);

        expect($receive($hostClient)['type'])
            ->toBe('conversation_transcript');

        expect($receive($hostClient)['type'])
            ->toBe('conversation_recognition_completed');

        // The fake worker takes 1500 ms, so disconnect before completion.
        $guestClient->disconnect();

        expect($receive($hostClient)['type'])
            ->toBe('conversation_peer_left');

        // Reconnect with the same guest token using a different socket.
        $replacementGuest = $connect();

        $send($replacementGuest, [
            'type' => 'conversation_join',
            'code' => $guest['code'],
            'token' => $guest['token'],
        ]);

        $joined = $receive($replacementGuest);

        expect($joined['type'])->toBe('conversation_joined')
            ->and($joined['role'])->toBe('guest');

        expect($receive($hostClient)['type'])
            ->toBe('conversation_peer_joined');

        // Wait beyond the fake worker duration.
        usleep(1900000);

        // A new turn must work; no cancelled translation may arrive first.
        $send($replacementGuest, [
            'type' => 'conversation_start',
            'mime_type' => 'audio/webm',
        ]);

        expect($receive($replacementGuest)['type'])
            ->toBe('conversation_turn_started');

        expect($receive($hostClient)['type'])
            ->toBe('conversation_peer_started');
    } finally {
        foreach ($clients as $client) {
            try {
                $client->disconnect();
            } catch (Throwable) {
                //
            }
        }

        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }

        config()->set('cache.default', $originalCache);
    }
});
