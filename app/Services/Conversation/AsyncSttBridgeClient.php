<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use JsonException;
use RuntimeException;

/**
 * Non-blocking, single-session TCP client for scripts/stt/vt21_async_stt_bridge.py.
 * Call poll() regularly from the WebSocket event loop.
 */
final class AsyncSttBridgeClient
{
    private const MAX_AUDIO_BYTES = 15360;

    private const MAX_LINE_BYTES = 25000;

    private const MAX_OUTBOUND_BYTES = 1048576;

    private const MAX_INBOUND_BYTES = 1048576;

    /** @var resource|null */
    private $socket = null;

    private string $outbound = '';

    private string $inbound = '';

    private bool $closed = false;

    private bool $stopRequested = false;

    private bool $done = false;

    private int $lastFinalSegment = 0;

    public function __construct(
        private readonly string $host = 'host.docker.internal',
        private readonly int $port = 8767,
        private readonly float $connectTimeoutSeconds = 2.0,
    ) {}

    public function start(string $language, int $sampleRate): void
    {
        if ($this->socket !== null || $this->closed) {
            throw new RuntimeException('STT bridge client has already started or closed.');
        }

        if (! in_array($language, ['ru-RU', 'en-US'], true)
            || $sampleRate < 8000 || $sampleRate > 48000
        ) {
            throw new RuntimeException('Invalid STT language or sample rate.');
        }

        $endpoint = sprintf('tcp://%s:%d', $this->host, $this->port);
        $socket = @stream_socket_client(
            $endpoint,
            $errno,
            $error,
            $this->connectTimeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            throw new RuntimeException("STT bridge connection failed ({$errno}): {$error}");
        }

        stream_set_blocking($socket, false);
        $this->socket = $socket;
        $this->queue(['type' => 'start', 'language' => $language, 'sample_rate' => $sampleRate]);
        $this->flush();
    }

    public function pushAudio(string $pcm16): void
    {
        $length = strlen($pcm16);

        if ($this->closed || $this->socket === null || $this->stopRequested) {
            throw new RuntimeException('STT session is not accepting audio.');
        }

        if ($length === 0 || $length > self::MAX_AUDIO_BYTES || $length % 2 !== 0) {
            throw new RuntimeException('PCM16 audio chunk must contain 2..15360 even bytes.');
        }

        $this->queue(['type' => 'audio', 'data' => base64_encode($pcm16)]);
        $this->flush();
    }

    /** Signal end-of-audio without blocking for Google recognition to finish. */
    public function finish(): void
    {
        if ($this->closed || $this->socket === null) {
            throw new RuntimeException('STT session is not running.');
        }

        if ($this->stopRequested) {
            return;
        }

        $this->stopRequested = true;
        $this->queue(['type' => 'stop']);
        $this->flush();
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws JsonException
     */
    public function poll(): array
    {
        if ($this->socket === null || $this->closed) {
            return [];
        }

        $this->flush();
        $events = [];

        while (true) {
            $data = @fread($this->socket, 65536);
            if ($data === false) {
                throw new RuntimeException('Unable to read STT bridge response.');
            }
            if ($data === '') {
                break;
            }

            $this->inbound .= $data;
            if (strlen($this->inbound) > self::MAX_INBOUND_BYTES) {
                throw new RuntimeException('STT bridge response buffer exceeded.');
            }
        }

        while (($pos = strpos($this->inbound, "\n")) !== false) {
            $line = substr($this->inbound, 0, $pos);
            $this->inbound = substr($this->inbound, $pos + 1);
            if ($line === '' || strlen($line) > self::MAX_LINE_BYTES) {
                throw new RuntimeException('Invalid STT bridge response length.');
            }

            $event = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($event) || ! is_string($event['type'] ?? null)) {
                throw new RuntimeException('Invalid STT bridge response.');
            }

            $type = $event['type'];
            if (! in_array($type, ['ready', 'interim', 'final', 'done', 'cancelled', 'error'], true)) {
                throw new RuntimeException('Unknown STT bridge event type.');
            }

            if (in_array($type, ['final', 'interim'], true)
                && ! is_string($event['text'] ?? null)
            ) {
                throw new RuntimeException('Invalid STT transcript.');
            }

            if ($type === 'final') {
                $segment = $event['segment'] ?? null;
                if (! is_int($segment) || $segment !== $this->lastFinalSegment + 1) {
                    throw new RuntimeException('Out-of-order STT final segment.');
                }
                $this->lastFinalSegment = $segment;
            }

            if ($type === 'done') {
                $this->done = true;
            }
            if ($type === 'error') {
                throw new RuntimeException('STT bridge: '.strval($event['message'] ?? 'Unknown error'));
            }

            $events[] = $event;
        }

        if (strlen($this->inbound) > self::MAX_LINE_BYTES) {
            throw new RuntimeException('STT bridge partial response exceeded maximum length.');
        }

        if (feof($this->socket) && ! $this->done) {
            throw new RuntimeException('STT bridge disconnected before done.');
        }

        return $events;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function pendingOutputBytes(): int
    {
        return strlen($this->outbound);
    }

    public function cancel(): void
    {
        // Socket close cancels bridge's Google streaming task, without waiting for a reply.
        $this->close();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
        $this->outbound = '';
        $this->inbound = '';
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @param array<string, mixed> $message */
    private function queue(array $message): void
    {
        $serialized = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        if (strlen($serialized) > self::MAX_LINE_BYTES) {
            throw new RuntimeException('STT bridge command exceeds maximum line length.');
        }
        if (strlen($this->outbound) + strlen($serialized) > self::MAX_OUTBOUND_BYTES) {
            throw new RuntimeException('STT bridge outbound audio backlog exceeded.');
        }
        $this->outbound .= $serialized;
    }

    private function flush(): void
    {
        if ($this->socket === null || $this->closed) {
            return;
        }
        while ($this->outbound !== '') {
            $written = @fwrite($this->socket, $this->outbound);
            if ($written === false) {
                throw new RuntimeException('Unable to send audio to STT bridge.');
            }
            if ($written === 0) {
                break;
            }
            $this->outbound = substr($this->outbound, $written);
        }
    }
}
