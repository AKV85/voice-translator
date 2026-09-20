<?php

namespace App\Contracts;

use App\DTO\AudioInput;
use App\DTO\TranscriptionResult;
use App\Enums\Language;

interface SpeechToTextProvider
{
    public function transcribe(
        AudioInput $audio,
        Language $sourceLanguage,
    ): TranscriptionResult;
}
