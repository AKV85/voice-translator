<?php

namespace Tests\Fakes;

use App\Contracts\SpeechToTextProvider;
use App\DTO\TranscriptionResult;
use App\Enums\Language;

final class FakeSpeechToTextProvider implements SpeechToTextProvider
{
    public ?string $receivedAudio = null;

    public ?Language $receivedLanguage = null;

    public function __construct(
        private readonly string $transcription = 'I need a truck',
    ) {}

    public function transcribe(
        string $audio,
        Language $sourceLanguage,
    ): TranscriptionResult {
        $this->receivedAudio = $audio;
        $this->receivedLanguage = $sourceLanguage;

        return new TranscriptionResult($this->transcription);
    }
}
