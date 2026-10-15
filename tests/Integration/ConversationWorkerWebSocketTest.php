<?php

use WebSocket\Client;

it('keeps WebSocket responsive while a worker is running', function (): void {
    $port = 18082;

    $process = null;
    $pipes = [];
    $clients = [];

    $logPath = sys_get_temp_dir()
        .'/vt21-worker-'.bin2hex(random_bytes(8)).'.log';

    try {
        $process = proc_open(
            [
                PHP_BINARY,
                base_path('tests/Support/Conversation/worker-websocket-server.php'),
                (string) $port,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $logPath, 'a'],
                2 => ['file', $logPath, 'a'],
            ],
            $pipes,
            base_path(),
        );

        expect($process)->not->toBeFalse();

        fclose($pipes[0]);

        $ready = false;

        for ($attempt = 0; $attempt < 300; $attempt++) {
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

            usleep(50000);
        }

        if (! $ready) {
            $status = proc_get_status($process);

            throw new RuntimeException(
                'Worker WebSocket server failed to start.'
                .' running='.($status['running'] ? 'yes' : 'no')
                .' exitcode='.$status['exitcode']
                .' log='.(is_file($logPath) ? file_get_contents($logPath) : 'missing'),
            );
        }

        $host = new Client("ws://127.0.0.1:{$port}");
        $host->setTimeout(5);
        $host->connect();
        $clients[] = $host;

        $guest = new Client("ws://127.0.0.1:{$port}");
        $guest->setTimeout(5);
        $guest->connect();
        $clients[] = $guest;

        foreach ([$host, $guest] as $client) {
            $client->text(json_encode([
                'type' => 'register',
            ], JSON_THROW_ON_ERROR));

            $registered = json_decode(
                $client->receive()->getContent(),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            expect($registered['type'])->toBe('registered');
        }

        $turnId = bin2hex(random_bytes(8));

        $host->text(json_encode([
            'type' => 'worker_start',
            'turn_id' => $turnId,
        ], JSON_THROW_ON_ERROR));

        $started = json_decode(
            $host->receive()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($started['type'])->toBe('worker_started')
            ->and($started['turn_id'])->toBe($turnId);

        $pingStartedAt = hrtime(true);

        $guest->text(json_encode([
            'type' => 'ping',
        ], JSON_THROW_ON_ERROR));

        $pong = json_decode(
            $guest->receive()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $pongElapsedMs = (hrtime(true) - $pingStartedAt) / 1_000_000;

        expect($pong['type'])->toBe('pong')
            ->and($pongElapsedMs)->toBeLessThan(1000);

        $completed = json_decode(
            $guest->receive()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($completed['type'])->toBe('worker_completed')
            ->and($completed['turn_id'])->toBe($turnId)
            ->and($completed['status'])->toBe('completed');

        // A worker result must not be delivered to a replacement guest.
        $staleTurnId = bin2hex(random_bytes(8));

        $host->text(json_encode([
            'type' => 'worker_start',
            'turn_id' => $staleTurnId,
        ], JSON_THROW_ON_ERROR));

        $staleStarted = json_decode(
            $host->receive()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($staleStarted['type'])->toBe('worker_started');

        $guest->disconnect();

        // Create and register a new connection.
        $replacement = new Client("ws://127.0.0.1:{$port}");
        $replacement->setTimeout(5);
        $replacement->connect();
        $clients[] = $replacement;

        $replacement->text(json_encode([
            'type' => 'register',
        ], JSON_THROW_ON_ERROR));

        $registered = json_decode(
            $replacement->receive()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($registered['type'])->toBe('registered');

        // Wait longer than the worker's configured 1500 ms.
        usleep(1800000);

        $replacement->text(json_encode([
            'type' => 'ping',
        ], JSON_THROW_ON_ERROR));

        $response = json_decode(
            $replacement->receive()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($response['type'])->toBe('pong');

        // The existing Host must also remain responsive.
        $host->text(json_encode([
            'type' => 'ping',
        ], JSON_THROW_ON_ERROR));

        $hostResponse = json_decode(
            $host->receive()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($hostResponse['type'])->toBe('pong');

        // Disconnecting the sender must cancel the pending worker.
        $hostDisconnectTurnId = bin2hex(random_bytes(8));

        $host->text(json_encode([
            'type' => 'worker_start',
            'turn_id' => $hostDisconnectTurnId,
        ], JSON_THROW_ON_ERROR));

        $startedBeforeDisconnect = json_decode(
            $host->receive()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($startedBeforeDisconnect['type'])->toBe('worker_started');

        $host->disconnect();

        usleep(1800000);

        // The replacement Guest must not receive the cancelled result.
        $replacement->text(json_encode([
            'type' => 'ping',
        ], JSON_THROW_ON_ERROR));

        $afterHostDisconnect = json_decode(
            $replacement->receive()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($afterHostDisconnect['type'])->toBe('pong');

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

        if (is_file($logPath)) {
            unlink($logPath);
        }
    }
});
