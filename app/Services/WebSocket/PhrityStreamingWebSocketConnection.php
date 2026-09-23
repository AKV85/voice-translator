<?php

namespace App\Services\WebSocket;

use App\Contracts\StreamingWebSocketConnection;
use Closure;
use WebSocket\Client;
use WebSocket\Connection;
use WebSocket\Message\Text;

final readonly class PhrityStreamingWebSocketConnection implements StreamingWebSocketConnection
{
    public function __construct(
        private Client $client,
    ) {}

    public function sendBinary(string $payload): void
    {
        $this->client->binary($payload);
    }

    public function sendText(string $payload): void
    {
        $this->client->text($payload);
    }

    public function onText(
        Closure $listener,
    ): void {
        $this->client->onText(
            static function (
                Client $client,
                Connection $connection,
                Text $message,
            ) use ($listener): void {
                $listener(
                    $message->getContent(),
                );
            },
        );
    }

    public function onTick(
        Closure $listener,
    ): void {
        $this->client->onTick(
            static function (
                Client $client,
            ) use ($listener): void {
                $listener();
            },
        );
    }

    public function start(
        float $timeoutSeconds,
    ): void {
        $this->client->start(
            $timeoutSeconds,
        );
    }

    public function stop(): void
    {
        $this->client->stop();
    }

    public function close(): void
    {
        $this->client->close();
    }
}
