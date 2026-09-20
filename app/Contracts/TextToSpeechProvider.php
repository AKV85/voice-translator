<?php

namespace App\Contracts;

use App\DTO\SpeechSynthesisResult;
use App\Enums\Language;

interface TextToSpeechProvider
{
    public function synthesize(
        string $text,
        Language $targetLanguage,
    ): SpeechSynthesisResult;
}
