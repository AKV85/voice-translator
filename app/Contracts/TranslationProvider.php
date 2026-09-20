<?php

namespace App\Contracts;

use App\DTO\TranslationResult;
use App\Enums\Language;

interface TranslationProvider
{
    public function translate(
        string $text,
        Language $sourceLanguage,
        Language $targetLanguage,
    ): TranslationResult;
}
