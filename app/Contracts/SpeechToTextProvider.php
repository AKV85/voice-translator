<?php

namespace App\Contracts;

use App\DTO\TranscriptionResult;
use App\Enums\Language;

interface SpeechToTextProvider
{
    public function transcribe(
        string $audio,
        Language $sourceLanguage,
    ): TranscriptionResult;
}
