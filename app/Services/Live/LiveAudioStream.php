<?php

namespace App\Services\Live;

use Fiber;
use Generator;
use LogicException;

final class LiveAudioStream
{
    /**
     * @var list<string>
     */
    private array $chunks = [];

    private bool $finished = false;

    private ?Fiber $waitingFiber = null;

    public function push(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        if ($this->finished) {
            throw new LogicException(
                'Cannot push audio after the live audio stream has finished.',
            );
        }

        $this->chunks[] = $chunk;

        $this->resumeWaitingFiber();
    }

    public function finish(): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;

        $this->resumeWaitingFiber();
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    /**
     * @return Generator<int, string>
     */
    public function chunks(): Generator
    {
        while (true) {
            while ($this->chunks !== []) {
                $chunk = array_shift(
                    $this->chunks,
                );

                yield $chunk;
            }

            if ($this->finished) {
                return;
            }

            $fiber = Fiber::getCurrent();

            if ($fiber === null) {
                throw new LogicException(
                    'Live audio stream must be consumed inside a Fiber.',
                );
            }

            $this->waitingFiber = $fiber;

            Fiber::suspend();

            $this->waitingFiber = null;
        }
    }

    private function resumeWaitingFiber(): void
    {
        if (
            $this->waitingFiber === null
            || ! $this->waitingFiber->isSuspended()
        ) {
            return;
        }

        $this->waitingFiber->resume();
    }
}
