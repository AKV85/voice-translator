<?php

namespace App\DTO;

final readonly class SpeechSynthesisResult
{
    public function __construct(
        public string $audio,
        public string $mimeType,
    ) {}
}
