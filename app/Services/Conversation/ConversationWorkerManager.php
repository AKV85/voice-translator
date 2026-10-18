<?php

namespace App\Services\Conversation;

use JsonException;
use LogicException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ConversationWorkerManager
{
    private const MAX_CONCURRENT_WORKERS = 4;

    /** @var array<string, Process> */
    private array $processes = [];

    private ?ConversationPersistentWorkerPool $persistentPool = null;

    /** @var array<string, string> */
    private array $streamBuffers = [];

    /** @var array<string, int> */
    private array $streamBytes = [];

    /** @var array<string, bool> */
    private array $streamCompleted = [];

    /** @var array<string, string> */
    private array $streamPhase = [];

    /** @var array<string, array<string, int|null>> */
    private array $streamLatency = [];

    public function __construct(
        private readonly array $workerCommand,
        private readonly float $timeoutSeconds = 10.0,
        bool $persistentMode = false,
    ) {
        if (
            $workerCommand === []
            || array_values($workerCommand) !== $workerCommand
            || count(array_filter(
                $workerCommand,
                static fn ($argument): bool => is_string($argument)
                    && $argument !== '',
            )) !== count($workerCommand)
        ) {
            throw new LogicException(
                'Invalid conversation worker command.',
            );
        }

        if ($timeoutSeconds <= 0) {
            throw new LogicException(
                'Conversation worker timeout must be positive.',
            );
        }

        if ($persistentMode) {
            $this->persistentPool = new ConversationPersistentWorkerPool(
                $workerCommand,
                $timeoutSeconds,
            );
        }
    }

    public function start(
        string $turnId,
        int $delayMs = 0,
    ): void {
        $this->launch($turnId, [
            'turn_id' => $turnId,
            'delay_ms' => $delayMs,
        ]);
    }

    /**
     * @param  array<string, mixed>  $job
     */
    public function startJob(string $turnId, array $job): void
    {
        if (
            ($job['turn_id'] ?? null) !== $turnId
            || ! is_string($job['source_text'] ?? null)
            || $job['source_text'] === ''
        ) {
            throw new LogicException(
                'Invalid conversation worker job.',
            );
        }

        if ($this->persistentPool !== null) {
            $this->persistentPool->startJob($turnId, $job);

            return;
        }

        $this->launch($turnId, $job);
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function launch(string $turnId, array $job): void
    {
        if ($turnId === '' || isset($this->processes[$turnId])) {
            throw new LogicException(
                'Invalid or duplicate conversation worker turn.',
            );
        }

        if (count($this->processes) >= self::MAX_CONCURRENT_WORKERS) {
            throw new RuntimeException(
                'Conversation worker capacity reached.',
            );
        }

        $process = new Process($this->workerCommand);

        $process->setTimeout($this->timeoutSeconds);
        $process->setInput(json_encode($job, JSON_THROW_ON_ERROR));

        $process->start();

        $this->processes[$turnId] = $process;
    }

    /**
     * @return array{
     *     turn_id: string,
     *     status: string,
     *     translated_text: string,
     *     audio_base64?: string,
     *     mime_type?: string
     * }|null
     *
     * @throws JsonException
     */
    public function poll(string $turnId): ?array
    {
        $process = $this->processes[$turnId] ?? null;

        if ($process === null) {
            return null;
        }

        try {
            $process->checkTimeout();
        } catch (ProcessTimedOutException $exception) {
            unset($this->processes[$turnId]);

            if ($process->isRunning()) {
                $process->stop(0.1);
            }

            throw new RuntimeException(
                'Conversation worker timed out.',
                previous: $exception,
            );
        }

        if ($process->isRunning()) {
            return null;
        }

        unset($this->processes[$turnId]);

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'Conversation worker failed: '.$process->getErrorOutput(),
            );
        }

        $result = json_decode(
            $process->getOutput(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (
            ! is_array($result)
            || ($result['turn_id'] ?? null) !== $turnId
            || ($result['status'] ?? null) !== 'completed'
            || ! is_string($result['translated_text'] ?? null)
        ) {
            throw new RuntimeException(
                'Invalid conversation worker response.',
            );
        }

        return $result;
    }

    /**
     * @return array{
     *     turn_id: string,
     *     status: string,
     *     translated_text: string,
     *     audio: string,
     *     mime_type: string
     * }|null
     */
    public function pollAudio(string $turnId): ?array
    {
        $result = $this->poll($turnId);

        if ($result === null) {
            return null;
        }

        $encodedAudio = $result['audio_base64'] ?? null;

        if (
            ! is_string($encodedAudio)
            || $encodedAudio === ''
            || strlen($encodedAudio) > 2796204
            || ($result['mime_type'] ?? null) !== 'audio/pcm'
        ) {
            throw new RuntimeException(
                'Invalid conversation worker audio response.',
            );
        }

        $audio = base64_decode($encodedAudio, true);

        if (
            $audio === false
            || $audio === ''
            || strlen($audio) > 2097152
            || strlen($audio) % 2 !== 0
        ) {
            throw new RuntimeException(
                'Invalid conversation worker audio response.',
            );
        }

        return [
            'turn_id' => $result['turn_id'],
            'status' => $result['status'],
            'translated_text' => $result['translated_text'],
            'audio' => $audio,
            'mime_type' => $result['mime_type'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pollStream(string $turnId): array
    {
        if ($this->persistentPool !== null) {
            return $this->persistentPool->pollStream($turnId);
        }

        $process = $this->processes[$turnId] ?? null;

        if ($process === null) {
            return [];
        }

        try {
            $process->checkTimeout();

            $pending = ($this->streamBuffers[$turnId] ?? '')
                .$process->getIncrementalOutput();

            $events = [];

            while (($position = strpos($pending, "\n")) !== false) {
                $line = substr($pending, 0, $position);
                $pending = substr($pending, $position + 1);

                if ($line === '' || strlen($line) > 16384) {
                    throw new RuntimeException(
                        'Invalid conversation stream event length.',
                    );
                }

                $event = json_decode(
                    $line,
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );

                if (
                    ! is_array($event)
                    || ($event['turn_id'] ?? null) !== $turnId
                ) {
                    throw new RuntimeException(
                        'Invalid conversation stream turn ID.',
                    );
                }

                $type = $event['type'] ?? null;

                $phase = $this->streamPhase[$turnId] ?? 'new';

                $expected = match ($phase) {
                    'new' => 'translation',
                    'translated' => 'tts_started',
                    'audio' => ['audio_chunk', 'completed'],
                    'completed' => [],
                    default => [],
                };

                if (! in_array($type, (array) $expected, true)) {
                    throw new RuntimeException(
                        'Invalid conversation stream event order.',
                    );
                }

                if ($type === 'translation') {
                    if (! is_string($event['translated_text'] ?? null)) {
                        throw new RuntimeException('Invalid translation event.');
                    }
                } elseif ($type === 'tts_started') {
                    if (($event['mime_type'] ?? null) !== 'audio/pcm') {
                        throw new RuntimeException('Invalid TTS format.');
                    }
                } elseif ($type === 'audio_chunk') {
                    $encoded = $event['audio_base64'] ?? null;

                    if (! is_string($encoded)) {
                        throw new RuntimeException('Invalid audio chunk.');
                    }

                    $audio = base64_decode($encoded, true);

                    if (
                        $audio === false
                        || $audio === ''
                        || strlen($audio) % 2 !== 0
                    ) {
                        throw new RuntimeException('Invalid PCM16 chunk.');
                    }

                    $total = ($this->streamBytes[$turnId] ?? 0)
                        + strlen($audio);

                    if ($total > 2097152) {
                        throw new RuntimeException('Audio limit exceeded.');
                    }

                    $this->streamBytes[$turnId] = $total;
                    unset($event['audio_base64']);
                    $event['audio'] = $audio;
                } elseif ($type === 'completed') {
                    if (
                        ($this->streamCompleted[$turnId] ?? false)
                        || ! is_int($event['audio_bytes'] ?? null)
                        || $event['audio_bytes']
                            !== ($this->streamBytes[$turnId] ?? 0)
                    ) {
                        throw new RuntimeException(
                            'Invalid audio completion size.',
                        );
                    }
                } else {
                    throw new RuntimeException(
                        'Unknown conversation stream event.',
                    );
                }

                if ($type === 'completed' && is_array($event['worker_latency'] ?? null)) {
                    // Keep only duration fields, never arbitrary worker diagnostics.
                    foreach (['startup_ms', 'translation_ms', 'translation_to_first_pcm_ms', 'total_ms'] as $metric) {
                        $value = $event['worker_latency'][$metric] ?? null;
                        if ($value === null || (is_int($value) && $value >= 0)) {
                            $this->streamLatency[$turnId][$metric] = $value;
                        }
                    }
                }

                $this->streamPhase[$turnId] = match ($type) {
                    'translation' => 'translated',
                    'tts_started', 'audio_chunk' => 'audio',
                    'completed' => 'completed',
                };

                if ($type === 'completed') {
                    $this->streamCompleted[$turnId] = true;

                    // Publish completion only after successful process exit.
                    continue;
                }

                $events[] = $event;
            }

            if (strlen($pending) > 16384) {
                throw new RuntimeException(
                    'Conversation stream buffer exceeded.',
                );
            }

            $this->streamBuffers[$turnId] = $pending;

            if (! $process->isRunning()) {
                if (! $process->isSuccessful()) {
                    throw new RuntimeException(
                        'Conversation worker failed: '
                        .$process->getErrorOutput(),
                    );
                }

                if (
                    $pending !== ''
                    || ! ($this->streamCompleted[$turnId] ?? false)
                ) {
                    throw new RuntimeException(
                        'Conversation worker stream ended unexpectedly.',
                    );
                }

                $events[] = [
                    'type' => 'completed',
                    'turn_id' => $turnId,
                    'audio_bytes' => $this->streamBytes[$turnId] ?? 0,
                    'worker_latency' => $this->streamLatency[$turnId] ?? [],
                ];

                unset(
                    $this->processes[$turnId],
                    $this->streamBuffers[$turnId],
                    $this->streamBytes[$turnId],
                    $this->streamCompleted[$turnId],
                    $this->streamPhase[$turnId],
                    $this->streamLatency[$turnId],
                );
            }

            return $events;
        } catch (\Throwable $exception) {
            $this->cancel($turnId);
            throw $exception;
        }
    }

    public function cancel(string $turnId): void
    {
        if ($this->persistentPool !== null) {
            $this->persistentPool->cancel($turnId);

            return;
        }

        $process = $this->processes[$turnId] ?? null;

        if ($process === null) {
            return;
        }

        unset(
            $this->processes[$turnId],
            $this->streamBuffers[$turnId],
            $this->streamBytes[$turnId],
            $this->streamCompleted[$turnId],
            $this->streamPhase[$turnId],
            $this->streamLatency[$turnId],
        );

        if ($process->isRunning()) {
            $process->stop(0.1);
        }
    }

    public function has(string $turnId): bool
    {
        return $this->persistentPool !== null
            ? $this->persistentPool->has($turnId)
            : isset($this->processes[$turnId]);
    }
}
