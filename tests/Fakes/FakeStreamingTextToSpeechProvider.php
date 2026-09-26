<?php

namespace Tests\Fakes;

use App\Contracts\StreamingTextToSpeechProvider;
use App\DTO\SpeechSynthesisResult;
use App\Enums\Language;
use Closure;
use RuntimeException;
use Throwable;

final class FakeStreamingTextToSpeechProvider implements StreamingTextToSpeechProvider
{
    public ?string $receivedText = null;

    public ?Language $receivedLanguage = null;

    public function __construct(
        private readonly string $audio,
        private readonly int $chunkSizeBytes = 317,
        private readonly ?Throwable $exception = null,
    ) {}

    public function synthesize(
        string $text,
        Language $targetLanguage,
        Closure $onAudioChunk,
    ): SpeechSynthesisResult {
        $this->receivedText = $text;
        $this->receivedLanguage = $targetLanguage;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        if ($this->chunkSizeBytes <= 0) {
            throw new RuntimeException(
                'Fake TTS chunk size must be greater than zero.',
            );
        }

        $length = strlen($this->audio);

        for (
            $offset = 0;
            $offset < $length;
            $offset += $this->chunkSizeBytes
        ) {
            $onAudioChunk(
                substr(
                    $this->audio,
                    $offset,
                    $this->chunkSizeBytes,
                ),
            );
        }

        return new SpeechSynthesisResult(
            audio: $this->audio,
            mimeType: 'audio/pcm',
        );
    }
}
