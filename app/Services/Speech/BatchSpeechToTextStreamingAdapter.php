<?php

namespace App\Services\Speech;

use App\Contracts\SpeechToTextProvider;
use App\Contracts\StreamingSpeechToTextProvider;
use App\DTO\AudioInput;
use App\DTO\StreamingTranscriptionResult;
use App\Enums\Language;
use Closure;

final readonly class BatchSpeechToTextStreamingAdapter implements StreamingSpeechToTextProvider
{
    public function __construct(
        private SpeechToTextProvider $batchProvider,
    ) {}

    public function transcribe(
        iterable $audioChunks,
        string $mimeType,
        Language $sourceLanguage,
        Closure $onTranscript,
    ): StreamingTranscriptionResult {
        $audio = '';

        foreach ($audioChunks as $chunk) {
            $audio .= $chunk;
        }

        $result = $this->batchProvider->transcribe(
            audio: new AudioInput(
                content: $audio,
                mimeType: $mimeType,
            ),
            sourceLanguage: $sourceLanguage,
        );

        $onTranscript(
            $result->text,
            true,
        );

        return new StreamingTranscriptionResult(
            text: $result->text,
        );
    }
}
