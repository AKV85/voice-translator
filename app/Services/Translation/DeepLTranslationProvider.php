<?php

namespace App\Services\Translation;

use App\Contracts\TranslationProvider;
use App\DTO\TranslationResult;
use App\Enums\Language;
use App\Exceptions\TranslationException;
use DeepL\DeepLClient;
use Throwable;

final readonly class DeepLTranslationProvider implements TranslationProvider
{
    public function __construct(
        private DeepLClient $client,
        private string $modelType = 'latency_optimized',
    ) {}

    public function translate(
        string $text,
        Language $sourceLanguage,
        Language $targetLanguage,
    ): TranslationResult {
        if (trim($text) === '') {
            throw new TranslationException(
                'Translation text must not be empty.',
            );
        }

        try {
            $result = $this->client->translateText(
                $text,
                $this->sourceLanguageCode($sourceLanguage),
                $this->targetLanguageCode($targetLanguage),
                [
                    'model_type' => $this->modelType,
                ],
            );
        } catch (Throwable $exception) {
            throw new TranslationException(
                'Translation provider failed.',
                previous: $exception,
            );
        }

        $translatedText = trim(
            $result->text,
        );

        if ($translatedText === '') {
            throw new TranslationException(
                'Translation provider returned an empty translation.',
            );
        }

        return new TranslationResult(
            text: $translatedText,
        );
    }

    private function sourceLanguageCode(
        Language $language,
    ): string {
        return match ($language) {
            Language::English => 'en',
            Language::Russian => 'ru',
        };
    }

    private function targetLanguageCode(
        Language $language,
    ): string {
        return match ($language) {
            Language::English => 'en-US',
            Language::Russian => 'ru',
        };
    }
}
