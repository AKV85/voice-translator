<?php

namespace App\DTO;

final readonly class StreamingTranscriptionEvent
{
    public function __construct(
        public string $text,
        public bool $isFinal,
        public float $receivedAtMs,
    ) {}
}
