<?php

namespace App\Contracts;

use Closure;

interface StreamingWebSocketConnection
{
    public function sendBinary(string $payload): void;

    public function sendText(string $payload): void;

    /**
     * @param  Closure(string): void  $listener
     */
    public function onText(Closure $listener): void;

    /**
     * @param  Closure(): void  $listener
     */
    public function onTick(Closure $listener): void;

    public function start(
        float $timeoutSeconds,
    ): void;

    public function stop(): void;

    public function close(): void;
}
