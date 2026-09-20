<?php

namespace App\DTO;

final readonly class TranslationResult
{
    public function __construct(
        public string $text,
    ) {}
}
