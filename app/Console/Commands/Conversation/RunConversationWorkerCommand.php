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
    protected $signature = 'conversation:run-worker {--persistent}';

    protected $description = 'Process a conversation pipeline job';

    private const PROFILE = 'chirp3-streaming-standard-deepl-openai';

    private const MAX_SOURCE_TEXT_BYTES = 8192;

    private const MAX_AUDIO_BYTES = 2097152;

    public function handle(): int
    {
        if (! $this->option('persistent')) {
            return $this->runOneJob(stream_get_contents(STDIN));
        }

        while (($line = fgets(STDIN)) !== false) {
            if (trim($line) === '') {
                continue;
            }

            if ($this->runOneJob($line) !== self::SUCCESS) {
                $job = json_decode($line, true);
                $turnId = is_array($job) && is_string($job['turn_id'] ?? null)
                    ? $job['turn_id']
                    : '';

                fwrite(STDOUT, json_encode([
                    'type' => 'error',
                    'turn_id' => $turnId,
                ], JSON_THROW_ON_ERROR)."\n");
                fflush(STDOUT);
            }
        }

        return self::SUCCESS;
    }

    private function runOneJob(string|false $input): int
    {
        try {

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

            if (($job['stream'] ?? false) === true) {
                $workerStarted = microtime(true);

                $requestedAt = $job['server_worker_requested_at'] ?? null;

                $startupMs = is_numeric($requestedAt)
                    ? (int) round(
                        ($workerStarted - (float) $requestedAt) * 1000,
                    )
                    : null;

                $translationAt = null;
                $firstAudioAt = null;

                $emit = static function (array $event) use ($job): void {
                    $event['turn_id'] = $job['turn_id'];

                    $json = json_encode($event, JSON_THROW_ON_ERROR);
                    $written = fwrite(STDOUT, $json."\n");

                    if ($written === false || $written !== strlen($json) + 1) {
                        throw new RuntimeException('Worker stdout write failed.');
                    }

                    fflush(STDOUT);
                };

                $buffer = '';
                $audioBytes = 0;

                $processed = $processor->processStreaming(
                    sourceText: $job['source_text'],
                    sourceLanguage: $sourceLanguage,
                    targetLanguage: $targetLanguage,
                    onTranslation: static function (string $text) use (
                        $emit,
                        $workerStarted,
                        $startupMs,
                        &$translationAt,
                    ): void {
                        $translationAt = microtime(true);

                        $emit([
                            'type' => 'translation',
                            'translated_text' => $text,
                            'startup_ms' => $startupMs,
                            'worker_elapsed_ms' => (int) round(
                                ($translationAt - $workerStarted) * 1000,
                            ),
                        ]);

                        $emit([
                            'type' => 'tts_started',
                            'mime_type' => 'audio/pcm',
                        ]);
                    },
                    onAudioChunk: static function (string $chunk) use (
                        $emit,
                        &$buffer,
                        &$audioBytes,
                        &$firstAudioAt,
                    ): void {
                        if ($firstAudioAt === null) {
                            $firstAudioAt = microtime(true);
                        }

                        $audioBytes += strlen($chunk);

                        if ($audioBytes > self::MAX_AUDIO_BYTES) {
                            throw new RuntimeException(
                                'Conversation audio size limit exceeded.',
                            );
                        }

                        $buffer .= $chunk;

                        // 80 ms PCM16 mono at 24 kHz.
                        while (strlen($buffer) >= 3840) {
                            $part = substr($buffer, 0, 3840);
                            $buffer = substr($buffer, 3840);

                            $emit([
                                'type' => 'audio_chunk',
                                'audio_base64' => base64_encode($part),
                            ]);
                        }
                    },
                );

                if (
                    $processed['mime_type'] !== 'audio/pcm'
                    || $audioBytes === 0
                    || $audioBytes % 2 !== 0
                    || $processed['audio_bytes'] !== $audioBytes
                ) {
                    throw new RuntimeException(
                        'Invalid streamed conversation audio.',
                    );
                }

                if ($buffer !== '') {
                    $emit([
                        'type' => 'audio_chunk',
                        'audio_base64' => base64_encode($buffer),
                    ]);
                }

                $emit([
                    'type' => 'completed',
                    'audio_bytes' => $audioBytes,
                    'worker_latency' => [
                        'translation_ms' => (int) round(
                            (($translationAt ?? microtime(true)) - $workerStarted) * 1000,
                        ),
                        'translation_to_first_pcm_ms' => $firstAudioAt === null
                            ? null
                            : (int) round(($firstAudioAt - $translationAt) * 1000),
                        'total_ms' => (int) round(
                            (microtime(true) - $workerStarted) * 1000,
                        ),
                    ],
                ]);

                return self::SUCCESS;
            }

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
