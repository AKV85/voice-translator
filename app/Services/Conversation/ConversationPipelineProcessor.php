<?php

namespace App\Services\Conversation;

use App\Contracts\StreamingTextToSpeechProvider;
use App\Contracts\TranslationProvider;
use App\Enums\Language;
use Closure;

final readonly class ConversationPipelineProcessor
{
    public function __construct(
        private TranslationProvider $translation,
        private StreamingTextToSpeechProvider $textToSpeech,
    ) {}

    /**
     * @return array{
     *     translated_text: string,
     *     audio: string,
     *     mime_type: string
     * }
     */
    /**
     * @param  Closure(string): void  $onTranslation
     * @param  Closure(string): void  $onAudioChunk
     * @return array{
     *     translated_text: string,
     *     mime_type: string,
     *     audio_bytes: int
     * }
     */
    public function processStreaming(
        string $sourceText,
        Language $sourceLanguage,
        Language $targetLanguage,
        Closure $onTranslation,
        Closure $onAudioChunk,
    ): array {
        $translationResult = $this->translation->translate(
            text: $sourceText,
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
        );

        $onTranslation($translationResult->text);

        $audioBytes = 0;

        $result = $this->textToSpeech->synthesize(
            text: $translationResult->text,
            targetLanguage: $targetLanguage,
            onAudioChunk: static function (string $chunk) use (
                $onAudioChunk,
                &$audioBytes,
            ): void {
                $audioBytes += strlen($chunk);
                $onAudioChunk($chunk);
            },
        );

        return [
            'translated_text' => $translationResult->text,
            'mime_type' => $result->mimeType,
            'audio_bytes' => $audioBytes,
        ];
    }

    public function process(
        string $sourceText,
        Language $sourceLanguage,
        Language $targetLanguage,
    ): array {
        $translationResult = $this->translation->translate(
            text: $sourceText,
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
        );

        $synthesisResult = $this->textToSpeech->synthesize(
            text: $translationResult->text,
            targetLanguage: $targetLanguage,
            onAudioChunk: static function (string $chunk): void {
                // The synthesis result contains the complete audio.
                // IPC serialization will happen in the worker entrypoint.
            },
        );

        return [
            'translated_text' => $translationResult->text,
            'audio' => $synthesisResult->audio,
            'mime_type' => $synthesisResult->mimeType,
        ];
    }
}
