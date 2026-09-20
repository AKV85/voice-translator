<?php

namespace App\DTO;

final readonly class SpeechTranslationResult
{
    public function __construct(
        public TranscriptionResult $transcription,
        public TranslationResult $translation,
        public SpeechSynthesisResult $speech,
    ) {}
}
