<?php

namespace App\Contracts;

use App\DTO\StreamingTranscriptionResult;
use App\Enums\Language;
use Closure;

interface StreamingSpeechToTextProvider
{
    /**
     * @param  iterable<int, string>  $audioChunks
     * @param  Closure(string, bool): void  $onTranscript
     */
    public function transcribe(
        iterable $audioChunks,
        string $mimeType,
        Language $sourceLanguage,
        Closure $onTranscript,
    ): StreamingTranscriptionResult;
}
