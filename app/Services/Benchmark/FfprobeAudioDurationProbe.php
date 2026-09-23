<?php

namespace App\Services\Benchmark;

use App\Contracts\AudioDurationProbe;
use RuntimeException;
use Symfony\Component\Process\Process;

final class FfprobeAudioDurationProbe implements AudioDurationProbe
{
    public function durationMs(string $audioPath): float
    {
        if (! is_file($audioPath)) {
            throw new RuntimeException(
                "Audio file not found: {$audioPath}"
            );
        }

        $process = new Process([
            'ffprobe',
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $audioPath,
        ]);

        $process->setTimeout(10);

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                sprintf(
                    'Unable to determine audio duration: %s',
                    trim($process->getErrorOutput()),
                )
            );
        }

        $durationSeconds = trim(
            $process->getOutput()
        );

        if (
            $durationSeconds === ''
            || ! is_numeric($durationSeconds)
        ) {
            throw new RuntimeException(
                sprintf(
                    'Invalid audio duration returned by ffprobe: %s',
                    $durationSeconds,
                )
            );
        }

        $durationMs = (float) $durationSeconds * 1000;

        if ($durationMs <= 0) {
            throw new RuntimeException(
                'Audio duration must be greater than zero.'
            );
        }

        return $durationMs;
    }
}
