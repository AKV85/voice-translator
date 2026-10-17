<?php

declare(strict_types=1);

use App\Services\Conversation\ConversationWorkerManager;
use Illuminate\Contracts\Console\Kernel;
use WebSocket\Connection;
use WebSocket\Message\Text;
use WebSocket\Server;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$port = (int) ($argv[1] ?? 0);

if ($port < 1 || $port > 65535) {
    fwrite(STDERR, "Invalid test WebSocket port.\n");
    exit(1);
}

$workers = new ConversationWorkerManager(
    workerCommand: [
        PHP_BINARY,
        base_path('tests/Support/Conversation/pipeline-worker.php'),
    ],
);

/** @var array<string, array{sender: Connection, receiver: Connection}> $jobs */
$jobs = [];

/** @var list<Connection> $participants */
$participants = [];

$send = static function (Connection $connection, array $payload): void {
    $connection->text(json_encode($payload, JSON_THROW_ON_ERROR));
};

$server = new Server(port: $port);

$server->onText(
    function (
        Server $server,
        Connection $connection,
        Text $message,
    ) use ($workers, &$jobs, &$participants, $send): void {
        try {
            $payload = json_decode(
                $message->getContent(),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            if (! is_array($payload)) {
                throw new RuntimeException('Invalid message.');
            }

            if (($payload['type'] ?? null) === 'register') {
                if (! in_array($connection, $participants, true)) {
                    $participants[] = $connection;
                }

                $send($connection, ['type' => 'registered']);

                return;
            }

            if (($payload['type'] ?? null) === 'ping') {
                $send($connection, ['type' => 'pong']);

                return;
            }

            if (($payload['type'] ?? null) !== 'worker_start') {
                throw new RuntimeException('Unsupported message.');
            }

            $turnId = $payload['turn_id'] ?? null;

            if (! is_string($turnId) || $turnId === '') {
                throw new RuntimeException('Invalid turn ID.');
            }

            $receiver = null;

            foreach ($participants as $participant) {
                if (
                    $participant !== $connection
                    && $participant->isConnected()
                ) {
                    $receiver = $participant;
                    break;
                }
            }

            if ($receiver === null) {
                throw new RuntimeException('Worker receiver unavailable.');
            }

            $workers->start($turnId, 1500);

            $jobs[$turnId] = [
                'sender' => $connection,
                'receiver' => $receiver,
            ];

            $send($connection, [
                'type' => 'worker_started',
                'turn_id' => $turnId,
            ]);
        } catch (Throwable $exception) {
            $send($connection, [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);
        }
    },
);

$server->onTick(
    function (Server $server) use (
        $workers,
        &$jobs,
        $send,
    ): void {
        foreach ($jobs as $turnId => $job) {
            $sender = $job['sender'];
            $receiver = $job['receiver'];

            if (
                ! $sender->isConnected()
                || ! $receiver->isConnected()
            ) {
                $workers->cancel($turnId);
                unset($jobs[$turnId]);

                continue;
            }

            try {
                $result = $workers->poll($turnId);

                if ($result === null) {
                    continue;
                }

                unset($jobs[$turnId]);

                $send($receiver, [
                    'type' => 'worker_completed',
                    ...$result,
                ]);
            } catch (Throwable $exception) {
                unset($jobs[$turnId]);
                $workers->cancel($turnId);

                if ($sender->isConnected()) {
                    $send($sender, [
                        'type' => 'error',
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        }
    },
);

$server->onDisconnect(
    function (Server $server, Connection $connection) use (
        $workers,
        &$jobs,
        &$participants,
    ): void {
        foreach ($jobs as $turnId => $job) {
            if (
                $job['sender'] !== $connection
                && $job['receiver'] !== $connection
            ) {
                continue;
            }

            $workers->cancel($turnId);
            unset($jobs[$turnId]);
        }

        $participants = array_values(
            array_filter(
                $participants,
                static fn (Connection $item): bool => $item !== $connection,
            ),
        );
    },
);

$server->start(0.005);
