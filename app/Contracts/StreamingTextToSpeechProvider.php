<?php

namespace App\Contracts;

use App\DTO\SpeechSynthesisResult;
use App\Enums\Language;
use Closure;

interface StreamingTextToSpeechProvider
{
    /**
     * @param  Closure(string): void  $onAudioChunk
     */
    public function synthesize(
        string $text,
        Language $targetLanguage,
        Closure $onAudioChunk,
    ): SpeechSynthesisResult;
}
