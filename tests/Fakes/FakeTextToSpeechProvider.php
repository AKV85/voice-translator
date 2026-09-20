<?php

namespace Tests\Fakes;

use App\Contracts\TextToSpeechProvider;
use App\DTO\SpeechSynthesisResult;
use App\Enums\Language;

final class FakeTextToSpeechProvider implements TextToSpeechProvider
{
    public ?string $receivedText = null;

    public ?Language $receivedLanguage = null;

    public function __construct(
        private readonly string $audio = 'fake-audio',
        private readonly string $mimeType = 'audio/mpeg',
    ) {}

    public function synthesize(
        string $text,
        Language $targetLanguage,
    ): SpeechSynthesisResult {
        $this->receivedText = $text;
        $this->receivedLanguage = $targetLanguage;

        return new SpeechSynthesisResult(
            audio: $this->audio,
            mimeType: $this->mimeType,
        );
    }
}
