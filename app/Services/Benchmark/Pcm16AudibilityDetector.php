<?php

namespace App\Services\Benchmark;

use RuntimeException;

final class Pcm16AudibilityDetector
{
    private string $buffer = '';

    private int $processedBytes = 0;

    private bool $audibleDetected = false;

    public function __construct(
        private readonly int $sampleRate = 24000,
        private readonly int $frameDurationMs = 10,
        private readonly float $rmsThreshold = 328.0,
    ) {
        if ($this->sampleRate <= 0) {
            throw new RuntimeException(
                'PCM sample rate must be greater than zero.',
            );
        }

        if ($this->frameDurationMs <= 0) {
            throw new RuntimeException(
                'PCM frame duration must be greater than zero.',
            );
        }

        if ($this->rmsThreshold < 0) {
            throw new RuntimeException(
                'PCM RMS threshold must not be negative.',
            );
        }
    }

    public function consume(
        string $chunk,
    ): ?float {
        if ($this->audibleDetected) {
            return null;
        }

        if ($chunk === '') {
            return null;
        }

        $this->buffer .= $chunk;

        $frameBytes = $this->frameBytes();

        while (strlen($this->buffer) >= $frameBytes) {
            $frame = substr(
                $this->buffer,
                0,
                $frameBytes,
            );

            if (
                $this->pcmRms($frame)
                >= $this->rmsThreshold
            ) {
                $this->audibleDetected = true;

                return $this->millisecondsForBytes(
                    $this->processedBytes,
                );
            }

            $this->buffer = substr(
                $this->buffer,
                $frameBytes,
            );

            $this->processedBytes += $frameBytes;
        }

        return null;
    }

    private function pcmRms(
        string $pcm,
    ): float {
        $sampleCount = intdiv(
            strlen($pcm),
            2,
        );

        if ($sampleCount === 0) {
            return 0.0;
        }

        $sumSquares = 0.0;

        for (
            $sampleIndex = 0;
            $sampleIndex < $sampleCount;
            $sampleIndex++
        ) {
            $byteOffset = $sampleIndex * 2;

            $value =
                ord($pcm[$byteOffset])
                | (
                    ord($pcm[$byteOffset + 1])
                    << 8
                );

            if ($value >= 0x8000) {
                $value -= 0x10000;
            }

            $sumSquares += $value * $value;
        }

        return sqrt(
            $sumSquares / $sampleCount,
        );
    }

    private function frameBytes(): int
    {
        $frameSamples = (int) (
            $this->sampleRate
            * $this->frameDurationMs
            / 1000
        );

        return $frameSamples * 2;
    }

    private function millisecondsForBytes(
        int $bytes,
    ): float {
        return (
            $bytes
            / (
                $this->sampleRate
                * 2
            )
        ) * 1000;
    }
}
