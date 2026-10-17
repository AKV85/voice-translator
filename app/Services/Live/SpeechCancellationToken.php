<?php

namespace App\Services\Live;

use Closure;

final class SpeechCancellationToken
{
    private bool $cancelled = false;

    private ?Closure $callback = null;

    public function onCancel(Closure $callback): void
    {
        if ($this->cancelled) {
            $callback();

            return;
        }

        $this->callback = $callback;
    }

    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;

        $callback = $this->callback;
        $this->callback = null;

        $callback?->__invoke();
    }

    public function clear(): void
    {
        $this->callback = null;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
