<?php

namespace App\Contracts;

interface StreamingWebSocketFactory
{
    /**
     * @param  array<string, string>  $headers
     */
    public function connect(
        string $url,
        array $headers = [],
        int $timeoutSeconds = 30,
    ): StreamingWebSocketConnection;
}
