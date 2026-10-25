<?php

namespace App\Console\Commands\Conversation;

use App\Contracts\StreamingTextToSpeechProvider;
use App\Contracts\TranslationProvider;
use App\Enums\Language;
use App\Services\Conversation\ConversationPcmBuffer;
use App\Services\Conversation\ConversationPipelineProcessor;
use App\Services\Conversation\ConversationTiming;
use App\Services\Speech\OpenAIStreamingTextToSpeechProvider;
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

            $ttsTimes = [];
            if ($ttsProvider instanceof OpenAIStreamingTextToSpeechProvider) {
                $ttsProvider = $ttsProvider->withTimingObserver(static function (string $stage, int $at) use (&$ttsTimes): void {
                    $ttsTimes[$stage] = $at;
                });
            }
            $processor = new ConversationPipelineProcessor(
                translation: $translationProvider,
                textToSpeech: $ttsProvider,
            );

            if (($job['stream'] ?? false) === true) {
                $workerStarted = hrtime(true);

                $requestedAt = $job['server_worker_requested_ns'] ?? null;

                $startupMs = is_numeric($requestedAt)
                    ? (int) round(
                        ($workerStarted - (float) $requestedAt) / 1_000_000,
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

                $buffer = new ConversationPcmBuffer((int) config('conversation.initial_pcm_bytes', 3840));
                $audioBytes = 0;
                $firstEmittedAt = null;

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
                        $translationAt = hrtime(true);

                        $emit([
                            'type' => 'translation',
                            'translated_text' => $text,
                            'startup_ms' => $startupMs,
                            'worker_elapsed_ms' => (int) round(
                                ($translationAt - $workerStarted) / 1_000_000,
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
                        &$firstEmittedAt,
                    ): void {
                        if ($chunk === '') {
                            return;
                        }

                        if ($firstAudioAt === null) {
                            $firstAudioAt = hrtime(true);
                        }

                        $audioBytes += strlen($chunk);

                        if ($audioBytes > self::MAX_AUDIO_BYTES) {
                            throw new RuntimeException(
                                'Conversation audio size limit exceeded.',
                            );
                        }

                        foreach ($buffer->push($chunk) as $part) {
                            $firstEmittedAt ??= hrtime(true);
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

                $tail = $buffer->finish();
                if ($tail !== '') {
                    $firstEmittedAt ??= hrtime(true);
                    $emit([
                        'type' => 'audio_chunk',
                        'audio_base64' => base64_encode($tail),
                    ]);
                }

                $emit([
                    'type' => 'completed',
                    'audio_bytes' => $audioBytes,
                    'worker_latency' => [
                        ...ConversationTiming::durations($job['server_latency'] ?? []),
                        'startup_ms' => $startupMs,
                        'translation_ms' => (int) round(
                            (($translationAt ?? hrtime(true)) - $workerStarted) / 1_000_000,
                        ),
                        'translation_to_first_pcm_ms' => $firstAudioAt === null
                            ? null
                            : (int) round(($firstAudioAt - $translationAt) / 1_000_000),
                        'tts_request_ms' => isset($ttsTimes['request']) ? round(($ttsTimes['request'] - $workerStarted) / 1_000_000, 3) : null,
                        'tts_request_to_headers_ms' => isset($ttsTimes['headers'], $ttsTimes['request']) ? round(($ttsTimes['headers'] - $ttsTimes['request']) / 1_000_000, 3) : null,
                        'tts_request_to_first_audio_ms' => isset($ttsTimes['first_audio'], $ttsTimes['request']) ? round(($ttsTimes['first_audio'] - $ttsTimes['request']) / 1_000_000, 3) : null,
                        'first_pcm_emitted_ms' => $firstEmittedAt === null ? null : round(($firstEmittedAt - $workerStarted) / 1_000_000, 3),
                        'first_audio_to_pcm_emit_ms' => $firstEmittedAt === null || $firstAudioAt === null ? null : round(($firstEmittedAt - $firstAudioAt) / 1_000_000, 3),
                        'total_ms' => (int) round(
                            (hrtime(true) - $workerStarted) / 1_000_000,
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
