<?php

namespace Tests\Fakes;

use App\Contracts\TranslationProvider;
use App\DTO\TranslationResult;
use App\Enums\Language;

final class FakeTranslationProvider implements TranslationProvider
{
    public ?string $receivedText = null;

    public ?Language $receivedSourceLanguage = null;

    public ?Language $receivedTargetLanguage = null;

    public function __construct(
        private readonly string $translation = 'Мне нужен грузовик',
    ) {}

    public function translate(
        string $text,
        Language $sourceLanguage,
        Language $targetLanguage,
    ): TranslationResult {
        $this->receivedText = $text;
        $this->receivedSourceLanguage = $sourceLanguage;
        $this->receivedTargetLanguage = $targetLanguage;

        return new TranslationResult($this->translation);
    }
}
