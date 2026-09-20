<?php

namespace App\Services;

use App\Contracts\SpeechToTextProvider;
use App\Contracts\TextToSpeechProvider;
use App\Contracts\TranslationProvider;
use App\DTO\AudioInput;
use App\DTO\SpeechTranslationResult;
use App\Enums\Language;

final readonly class SpeechTranslationService
{
    public function __construct(
        private SpeechToTextProvider $speechToText,
        private TranslationProvider $translator,
        private TextToSpeechProvider $textToSpeech,
    ) {}

    public function translate(
        AudioInput $audio,
        Language $sourceLanguage,
        Language $targetLanguage,
    ): SpeechTranslationResult {
        $transcription = $this->speechToText->transcribe(
            $audio,
            $sourceLanguage,
        );

        $translation = $this->translator->translate(
            $transcription->text,
            $sourceLanguage,
            $targetLanguage,
        );

        $speech = $this->textToSpeech->synthesize(
            $translation->text,
            $targetLanguage,
        );

        return new SpeechTranslationResult(
            transcription: $transcription,
            translation: $translation,
            speech: $speech,
        );
    }
}
