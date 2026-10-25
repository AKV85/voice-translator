<?php

namespace App\Services\Conversation;

use InvalidArgumentException;
use RuntimeException;

/** Repacketizes PCM bytes without changing samples, including odd network fragments. */
final class ConversationPcmBuffer
{
    private string $buffer = '';

    private bool $first = true;

    private int $bytes = 0;

    public function __construct(private readonly int $initialBytes = 3840)
    {
        if (! in_array($initialBytes, [1920, 3840], true)) {
            throw new InvalidArgumentException('Initial PCM buffer must be 1920 or 3840 bytes.');
        }
    }

    /** @return list<string> */
    public function push(string $chunk): array
    {
        $this->bytes += strlen($chunk);
        if ($this->bytes > 2097152) {
            throw new RuntimeException('Conversation audio size limit exceeded.');
        }
        $this->buffer .= $chunk;
        $parts = [];
        while (strlen($this->buffer) >= ($size = $this->first ? $this->initialBytes : 3840)) {
            $parts[] = substr($this->buffer, 0, $size);
            $this->buffer = substr($this->buffer, $size);
            $this->first = false;
        }

        return $parts;
    }

    public function finish(): string
    {
        if ($this->bytes === 0 || $this->bytes % 2 !== 0) {
            throw new RuntimeException('Invalid streamed conversation PCM16 audio.');
        }
        $tail = $this->buffer;
        $this->buffer = '';

        return $tail;
    }
}
