<?php

namespace App\DTO;

final readonly class TranscriptionResult
{
    public function __construct(
        public string $text,
    ) {}
}
