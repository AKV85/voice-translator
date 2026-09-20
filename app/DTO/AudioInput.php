<?php

namespace App\DTO;

final readonly class AudioInput
{
    public function __construct(
        public string $content,
        public string $mimeType,
    ) {}
}
