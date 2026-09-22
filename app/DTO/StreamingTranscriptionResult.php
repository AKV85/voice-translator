<?php

namespace App\DTO;

final readonly class StreamingTranscriptionResult
{
    /**
     * @param  array<int, StreamingTranscriptionEvent>  $events
     */
    public function __construct(
        public string $text,
        public array $events = [],
    ) {}
}
