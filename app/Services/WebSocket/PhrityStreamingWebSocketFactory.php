<?php

namespace App\Services\WebSocket;

use App\Contracts\StreamingWebSocketConnection;
use App\Contracts\StreamingWebSocketFactory;
use WebSocket\Client;

final class PhrityStreamingWebSocketFactory implements StreamingWebSocketFactory
{
    public function connect(
        string $url,
        array $headers = [],
        int $timeoutSeconds = 30,
    ): StreamingWebSocketConnection {
        $client = new Client($url);

        $client->setTimeout(
            $timeoutSeconds,
        );

        foreach ($headers as $name => $value) {
            $client->addHeader(
                $name,
                $value,
            );
        }

        return new PhrityStreamingWebSocketConnection(
            client: $client,
        );
    }
}
