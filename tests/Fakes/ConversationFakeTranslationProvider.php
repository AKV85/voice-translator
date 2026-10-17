<?php

namespace Tests\Fakes;

use App\Contracts\TranslationProvider;
use App\DTO\TranslationResult;
use App\Enums\Language;

final class ConversationFakeTranslationProvider implements TranslationProvider
{
    public function translate(
        string $text,
        Language $sourceLanguage,
        Language $targetLanguage,
    ): TranslationResult {
        $failureFile = config('conversation.integration_failure_file');

        if (
            is_string($failureFile)
            && is_file($failureFile)
            && trim((string) file_get_contents($failureFile)) === 'translation'
        ) {
            unlink($failureFile);

            throw new \RuntimeException(
                'Simulated conversation translation failure.',
            );
        }

        return new TranslationResult(
            text: "FAKE-TRANSLATION-{$sourceLanguage->value}-{$targetLanguage->value}:{$text}",
        );
    }
}
