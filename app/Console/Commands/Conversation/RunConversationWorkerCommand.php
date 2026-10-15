<?php

namespace App\Console\Commands\Conversation;

use App\Contracts\StreamingTextToSpeechProvider;
use App\Contracts\TranslationProvider;
use App\Enums\Language;
use App\Services\Conversation\ConversationPipelineProcessor;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class RunConversationWorkerCommand extends Command
{
    protected $signature = 'conversation:run-worker';

    protected $description = 'Process a conversation pipeline job';

    private const PROFILE = 'chirp3-streaming-standard-deepl-openai';

    private const MAX_SOURCE_TEXT_BYTES = 8192;

    private const MAX_AUDIO_BYTES = 2097152;

    public function handle(): int
    {
        try {
            $input = stream_get_contents(STDIN);

            if ($input === false) {
                throw new RuntimeException('Unable to read worker input.');
            }

            $job = json_decode(
                $input,
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            if (
                ! is_array($job)
                || ! is_string($job['turn_id'] ?? null)
                || $job['turn_id'] === ''
                || strlen($job['turn_id']) > 128
                || ! is_string($job['source_text'] ?? null)
                || trim($job['source_text']) === ''
                || strlen($job['source_text']) > self::MAX_SOURCE_TEXT_BYTES
                || ! is_string($job['source_language'] ?? null)
                || ! is_string($job['target_language'] ?? null)
            ) {
                throw new RuntimeException('Invalid worker job.');
            }

            $sourceLanguage = Language::tryFrom(
                $job['source_language'],
            );

            $targetLanguage = Language::tryFrom(
                $job['target_language'],
            );

            if (
                $sourceLanguage === null
                || $targetLanguage === null
                || $sourceLanguage === $targetLanguage
            ) {
                throw new RuntimeException('Invalid worker languages.');
            }

            $profile = config(
                'benchmarks.translation.pipeline.providers.'.self::PROFILE,
            );

            $translation = $profile['translation'] ?? null;
            $tts = $profile['text_to_speech'] ?? null;

            if (
                ! is_array($translation)
                || ! is_array($tts)
                || ! is_string($translation['contract'] ?? null)
                || ! is_string($tts['contract'] ?? null)
                || ! is_array($translation['config'] ?? null)
                || ! is_array($tts['config'] ?? null)
            ) {
                throw new RuntimeException(
                    'Conversation worker profile is invalid.',
                );
            }

            config([
                ...$translation['config'],
                ...$tts['config'],
            ]);

            $translationProvider = app($translation['contract']);
            $ttsProvider = app($tts['contract']);

            if (
                ! $translationProvider instanceof TranslationProvider
                || ! $ttsProvider instanceof StreamingTextToSpeechProvider
            ) {
                throw new RuntimeException(
                    'Conversation worker providers are invalid.',
                );
            }

            $processor = new ConversationPipelineProcessor(
                translation: $translationProvider,
                textToSpeech: $ttsProvider,
            );

            $processed = $processor->process(
                sourceText: $job['source_text'],
                sourceLanguage: $sourceLanguage,
                targetLanguage: $targetLanguage,
            );

            if (
                $processed['mime_type'] !== 'audio/pcm'
                || $processed['audio'] === ''
                || strlen($processed['audio']) > self::MAX_AUDIO_BYTES
                || strlen($processed['audio']) % 2 !== 0
            ) {
                throw new RuntimeException(
                    'Invalid conversation worker audio.',
                );
            }

            $result = [
                'turn_id' => $job['turn_id'],
                'status' => 'completed',
                'translated_text' => $processed['translated_text'],
                'audio_base64' => base64_encode($processed['audio']),
                'mime_type' => $processed['mime_type'],
            ];

            fwrite(
                STDOUT,
                json_encode($result, JSON_THROW_ON_ERROR),
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception->getMessage().PHP_EOL);

            return self::FAILURE;
        }
    }
}
