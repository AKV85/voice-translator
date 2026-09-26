<?php

namespace App\Contracts;

use App\DTO\RealtimeTranslationResult;
use App\Enums\Language;
use Closure;

interface RealtimeTranslationProvider
{
    /**
     * Translate a realtime PCM16 mono 24 kHz audio stream.
     *
     * @param  iterable<int, string>  $pcm16Chunks
     */
    public function translate(
        iterable $pcm16Chunks,
        Language $sourceLanguage,
        Language $targetLanguage,
        ?Closure $onSourceTranscript = null,
        ?Closure $onTranslatedTranscript = null,
        ?Closure $onTranslatedAudio = null,
    ): RealtimeTranslationResult;
}
