<?php

use App\Services\Conversation\ConversationStore;
use Illuminate\Support\Facades\Cache;
use WebSocket\Client;
use WebSocket\Message\Binary;

it('connects two participants through the real WebSocket server', function (): void {
    $port = 18081;

    $cachePath = sys_get_temp_dir()
        .'/vt21-websocket-'.bin2hex(random_bytes(8));

    mkdir($cachePath, 0700, true);

    $originalCache = config('cache.default');

    $process = null;
    $pipes = [];
    $clients = [];

    try {
        config()->set('conversation.enabled', true);
        config()->set('cache.default', 'vt21_integration');
        config()->set('cache.stores.vt21_integration', [
            'driver' => 'file',
            'path' => $cachePath,
            'lock_path' => $cachePath,
        ]);

        $store = app(ConversationStore::class);
        $host = $store->create();
        $guest = $store->join($host['code']);

        $command = [
            PHP_BINARY,
            base_path('tests/Support/Conversation/serve-websocket.php'),
            (string) $port,
            $cachePath,
        ];

        $process = proc_open(
            $command,
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

        // Wait until the server accepts connections.
        $ready = false;

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

            if (is_resource($socket)) {
                fclose($socket);
                $ready = true;
                break;
            }

            $status = proc_get_status($process);

            if (! $status['running']) {
                break;
            }

            usleep(100000);
        }

        if (! $ready) {
            $logPath = $cachePath.'/server.log';

            $log = is_file($logPath)
                ? file_get_contents($logPath)
                : 'Server log not found.';

            $status = proc_get_status($process);

            throw new RuntimeException(
                'WebSocket server did not start. '
                .'running='.($status['running'] ? 'yes' : 'no')
                .', exitcode='.$status['exitcode']
                ."\nSERVER LOG:\n".$log,
            );
        }

        $connect = function () use ($port, &$clients): Client {
            $client = new Client("ws://127.0.0.1:{$port}");
            $client->setTimeout(3);
            $client->connect();
            $clients[] = $client;

            return $client;
        };

        $receive = function (Client $client): array {
            return json_decode(
                $client->receive()->getContent(),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        };

        $receivePeerAudio = function (
            Client $client,
            string $sourceLanguage,
            string $targetLanguage,
        ) use ($receive): void {
            $translation = $receive($client);

            expect($translation['type'])
                ->toBe('conversation_translation_completed')
                ->and($translation['source_language'])
                ->toBe($sourceLanguage)
                ->and($translation['target_language'])
                ->toBe($targetLanguage)
                ->and($translation['translated_text'])
                ->toBe(
                    "FAKE-TRANSLATION-{$sourceLanguage}-{$targetLanguage}:Integration test transcript"
                );

            $tts = $receive($client);

            expect($tts['type'])
                ->toBe('conversation_tts_started')
                ->and($tts['format'])
                ->toBe('pcm16')
                ->and($tts['sample_rate_hz'])
                ->toBe(24000);

            $expectedAudio = pack('v*', 0, 1200, 2400, 1200, 0);

            $chunkA = $client->receive();
            $chunkB = $client->receive();

            expect($chunkA)->toBeInstanceOf(Binary::class)
                ->and($chunkB)
                ->toBeInstanceOf(Binary::class)
                ->and($chunkA->getContent().$chunkB->getContent())
                ->toBe($expectedAudio);

            $completed = $receive($client);

            expect($completed['type'])
                ->toBe('conversation_completed')
                ->and($completed['translated_audio_bytes'])
                ->toBe(strlen($expectedAudio));

            expect($receive($client)['type'])
                ->toBe('conversation_peer_stopped');
        };

        $hostClient = $connect();

        $hostClient->text(json_encode([
            'type' => 'conversation_join',
            'code' => $host['code'],
            'token' => $host['token'],
        ], JSON_THROW_ON_ERROR));

        $hostJoined = $receive($hostClient);

        expect($hostJoined['type'])->toBe('conversation_joined')
            ->and($hostJoined['role'])->toBe('host')
            ->and($hostJoined['peer_connected'])->toBeFalse();

        $guestClient = $connect();

        $guestClient->text(json_encode([
            'type' => 'conversation_join',
            'code' => $guest['code'],
            'token' => $guest['token'],
        ], JSON_THROW_ON_ERROR));

        $guestJoined = $receive($guestClient);
        $hostNotification = $receive($hostClient);

        expect($guestJoined['type'])->toBe('conversation_joined')
            ->and($guestJoined['role'])->toBe('guest')
            ->and($guestJoined['peer_connected'])->toBeTrue()
            ->and($hostNotification['type'])->toBe('conversation_peer_joined');

        // Host acquires the conversation turn.
        $hostClient->text(json_encode([
            'type' => 'conversation_start',
            'mime_type' => 'audio/webm',
        ], JSON_THROW_ON_ERROR));

        $hostTurn = $receive($hostClient);

        expect($hostTurn['type'])
            ->toBe('conversation_turn_started');

        $peerStarted = $receive($guestClient);

        expect($peerStarted['type'])
            ->toBe('conversation_peer_started');

        $hostClient->binary('host-audio');

        // Guest cannot acquire the same turn.
        $guestClient->text(json_encode([
            'type' => 'conversation_start',
        ], JSON_THROW_ON_ERROR));

        $guestError = $receive($guestClient);

        expect($guestError['type'])
            ->toBe('conversation_turn_busy');

        // Host releases the turn.
        $hostClient->text(json_encode([
            'type' => 'conversation_stop',
        ], JSON_THROW_ON_ERROR));

        $hostRecognized = $receive($hostClient);
        $hostStopped = $receive($hostClient);

        expect($hostRecognized['type'])
            ->toBe('conversation_transcript')
            ->and($hostRecognized['text'])
            ->toBe('Integration test transcript')
            ->and($hostStopped['type'])
            ->toBe('conversation_recognition_completed')
            ->and($hostStopped['text'])
            ->toBe('Integration test transcript');

        expect($receive($hostClient)['type'])
            ->toBe('conversation_turn_stopped');

        $receivePeerAudio($guestClient, 'ru', 'en');

        // Guest can acquire the turn after release.
        $guestClient->text(json_encode([
            'type' => 'conversation_start',
            'mime_type' => 'audio/webm',
        ], JSON_THROW_ON_ERROR));

        $guestTurn = $receive($guestClient);
        $hostPeerStarted = $receive($hostClient);

        expect($guestTurn['type'])
            ->toBe('conversation_turn_started')
            ->and($hostPeerStarted['type'])
            ->toBe('conversation_peer_started');

        $guestClient->binary('guest-audio');

        $guestClient->text(json_encode([
            'type' => 'conversation_stop',
        ], JSON_THROW_ON_ERROR));

        expect($receive($guestClient)['type'])
            ->toBe('conversation_transcript');

        expect($receive($guestClient)['type'])
            ->toBe('conversation_recognition_completed');

        expect($receive($guestClient)['type'])
            ->toBe('conversation_turn_stopped');

        $receivePeerAudio($hostClient, 'en', 'ru');

        expect(file_get_contents($cachePath.'/received-audio.bin'))
            ->toBe('host-audioguest-audio');

        // Provider failures must release turn ownership.
        foreach (['translation', 'tts'] as $failureMode) {
            file_put_contents(
                $cachePath.'/failure-mode.txt',
                $failureMode,
            );

            $hostClient->text(json_encode([
                'type' => 'conversation_start',
                'mime_type' => 'audio/webm',
            ], JSON_THROW_ON_ERROR));

            expect($receive($hostClient)['type'])
                ->toBe('conversation_turn_started');

            expect($receive($guestClient)['type'])
                ->toBe('conversation_peer_started');

            $hostClient->binary('failure-audio');

            $hostClient->text(json_encode([
                'type' => 'conversation_stop',
            ], JSON_THROW_ON_ERROR));

            expect($receive($hostClient)['type'])
                ->toBe('conversation_transcript');

            expect($receive($hostClient)['type'])
                ->toBe('conversation_recognition_completed');

            if ($failureMode === 'tts') {
                expect($receive($guestClient)['type'])
                    ->toBe('conversation_translation_completed');

                expect($receive($guestClient)['type'])
                    ->toBe('conversation_tts_started');

                $firstChunk = $guestClient->receive();

                expect($firstChunk)
                    ->toBeInstanceOf(Binary::class);
            }

            $error = $receive($hostClient);

            expect($error['type'])
                ->toBe('error')
                ->and($error['message'])
                ->toBe(
                    $failureMode === 'translation'
                        ? 'Simulated conversation translation failure.'
                        : 'Simulated conversation TTS failure.'
                );

            expect($receive($guestClient)['type'])
                ->toBe('conversation_peer_stopped');

            // The other participant must be able to start again.
            $guestClient->text(json_encode([
                'type' => 'conversation_start',
                'mime_type' => 'audio/webm',
            ], JSON_THROW_ON_ERROR));

            expect($receive($guestClient)['type'])
                ->toBe('conversation_turn_started');

            expect($receive($hostClient)['type'])
                ->toBe('conversation_peer_started');

            $guestClient->binary('recovery-audio');

            $guestClient->text(json_encode([
                'type' => 'conversation_stop',
            ], JSON_THROW_ON_ERROR));

            expect($receive($guestClient)['type'])
                ->toBe('conversation_transcript');

            expect($receive($guestClient)['type'])
                ->toBe('conversation_recognition_completed');

            expect($receive($guestClient)['type'])
                ->toBe('conversation_turn_stopped');

            $receivePeerAudio($hostClient, 'en', 'ru');
        }

        // Audio provider failure must cancel STT and release the turn.
        $hostClient->text(json_encode([
            'type' => 'conversation_start',
            'mime_type' => 'audio/webm',
        ], JSON_THROW_ON_ERROR));

        expect($receive($hostClient)['type'])
            ->toBe('conversation_turn_started');

        expect($receive($guestClient)['type'])
            ->toBe('conversation_peer_started');

        $hostClient->binary('FAIL_AUDIO_CHUNK');

        $audioError = $receive($hostClient);

        expect($audioError['type'])
            ->toBe('error')
            ->and($audioError['message'])
            ->toBe('Simulated conversation audio failure.');

        expect($receive($guestClient)['type'])
            ->toBe('conversation_peer_stopped');

        // Guest must be able to acquire the turn after the failure.
        $guestClient->text(json_encode([
            'type' => 'conversation_start',
            'mime_type' => 'audio/webm',
        ], JSON_THROW_ON_ERROR));

        expect($receive($guestClient)['type'])
            ->toBe('conversation_turn_started');

        expect($receive($hostClient)['type'])
            ->toBe('conversation_peer_started');

        $guestClient->binary('recovered-after-audio-error');

        $guestClient->text(json_encode([
            'type' => 'conversation_stop',
        ], JSON_THROW_ON_ERROR));

        expect($receive($guestClient)['type'])
            ->toBe('conversation_transcript');

        expect($receive($guestClient)['type'])
            ->toBe('conversation_recognition_completed');

        expect($receive($guestClient)['type'])
            ->toBe('conversation_turn_stopped');

        $receivePeerAudio($hostClient, 'en', 'ru');

        $invalidClient = $connect();

        $invalidClient->text(json_encode([
            'type' => 'conversation_join',
            'code' => $host['code'],
            'token' => 'invalid-token',
        ], JSON_THROW_ON_ERROR));

        $error = $receive($invalidClient);

        expect($error['type'])->toBe('conversation_session_invalid');

        $invalidClient->disconnect();
        $guestClient->disconnect();

        $hostNotification = $receive($hostClient);

        expect($hostNotification['type'])->toBe('conversation_peer_left');

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

            for ($attempt = 0; $attempt < 20; $attempt++) {
                if (! proc_get_status($process)['running']) {
                    break;
                }

                usleep(100000);
            }

            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }

            proc_close($process);
        }

        config()->set('cache.default', $originalCache);
        Cache::purge('vt21_integration');

        // Remove only the unique directory created by this test.
        if (is_dir($cachePath)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $cachePath,
                    FilesystemIterator::SKIP_DOTS,
                ),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($files as $file) {
                $file->isDir()
                    ? rmdir($file->getPathname())
                    : unlink($file->getPathname());
            }

            rmdir($cachePath);
        }
    }
});
