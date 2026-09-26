<?php

namespace Tests\Fakes;

use App\Contracts\StreamingSpeechToTextProvider;
use App\DTO\StreamingTranscriptionResult;
use App\Enums\Language;
use Closure;
use Throwable;

final class FakeStreamingSpeechToTextProvider implements StreamingSpeechToTextProvider
{
    /**
     * @var list<string>
     */
    public array $receivedChunks = [];

    public ?string $receivedMimeType = null;

    public ?Language $receivedLanguage = null;

    public function __construct(
        private readonly string $transcription = 'I need a truck',
        private readonly ?Throwable $exception = null,
    ) {}

    public function transcribe(
        iterable $audioChunks,
        string $mimeType,
        Language $sourceLanguage,
        Closure $onTranscript,
    ): StreamingTranscriptionResult {
        $this->receivedMimeType = $mimeType;
        $this->receivedLanguage = $sourceLanguage;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        foreach ($audioChunks as $chunk) {
            $this->receivedChunks[] = $chunk;
        }

        $onTranscript(
            $this->transcription,
            true,
        );

        return new StreamingTranscriptionResult(
            text: $this->transcription,
        );
    }
}
