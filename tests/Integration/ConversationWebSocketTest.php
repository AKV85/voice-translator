<?php

use App\Services\Conversation\ConversationStore;
use Illuminate\Support\Facades\Cache;
use WebSocket\Client;

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

        $invalidClient = $connect();

        $invalidClient->text(json_encode([
            'type' => 'conversation_join',
            'code' => $host['code'],
            'token' => 'invalid-token',
        ], JSON_THROW_ON_ERROR));

        $error = $receive($invalidClient);

        expect($error['type'])->toBe('error');

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
