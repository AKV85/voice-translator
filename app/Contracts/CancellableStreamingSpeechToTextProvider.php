<?php

namespace App\Contracts;

use App\DTO\StreamingTranscriptionResult;
use App\Enums\Language;
use App\Services\Live\SpeechCancellationToken;
use Closure;

interface CancellableStreamingSpeechToTextProvider extends StreamingSpeechToTextProvider
{
    public function transcribeCancellable(
        iterable $audioChunks,
        string $mimeType,
        Language $sourceLanguage,
        Closure $onTranscript,
        SpeechCancellationToken $cancellationToken,
    ): StreamingTranscriptionResult;
}
