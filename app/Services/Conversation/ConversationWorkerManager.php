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
        private readonly string $workerPath,
        private readonly float $timeoutSeconds = 10.0,
    ) {
        if (! is_file($workerPath) || ! is_readable($workerPath)) {
            throw new LogicException(
                'Conversation worker script is unavailable.',
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

        $process = new Process([
            PHP_BINARY,
            $this->workerPath,
        ]);

        $process->setTimeout($this->timeoutSeconds);
        $process->setInput(json_encode([
            'turn_id' => $turnId,
            'delay_ms' => $delayMs,
        ], JSON_THROW_ON_ERROR));

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
