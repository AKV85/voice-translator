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

    public function __construct(
        private readonly array $workerCommand,
        private readonly float $timeoutSeconds = 10.0,
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
     * @return array{turn_id: string, status: string, translated_text: string}|null
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

    public function cancel(string $turnId): void
    {
        $process = $this->processes[$turnId] ?? null;

        if ($process === null) {
            return;
        }

        unset($this->processes[$turnId]);

        if ($process->isRunning()) {
            $process->stop(0.1);
        }
    }

    public function has(string $turnId): bool
    {
        return isset($this->processes[$turnId]);
    }
}
