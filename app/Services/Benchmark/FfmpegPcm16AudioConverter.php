<?php

namespace App\Services\Benchmark;

use App\Contracts\Pcm16AudioConverter;
use RuntimeException;
use Symfony\Component\Process\Process;

final class FfmpegPcm16AudioConverter implements Pcm16AudioConverter
{
    public function convert(
        string $audioPath,
        int $sampleRate,
        int $channels,
    ): string {
        if ($sampleRate <= 0) {
            throw new RuntimeException(
                'PCM sample rate must be greater than zero.',
            );
        }

        if ($channels <= 0) {
            throw new RuntimeException(
                'PCM channel count must be greater than zero.',
            );
        }

        $process = new Process([
            'ffmpeg',
            '-v',
            'error',
            '-i',
            $audioPath,
            '-f',
            's16le',
            '-acodec',
            'pcm_s16le',
            '-ac',
            (string) $channels,
            '-ar',
            (string) $sampleRate,
            'pipe:1',
        ]);

        $process->setTimeout(60);

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'FFmpeg PCM conversion failed: '
                .trim($process->getErrorOutput()),
            );
        }

        $pcm = $process->getOutput();

        if ($pcm === '') {
            throw new RuntimeException(
                'FFmpeg PCM conversion returned empty audio.',
            );
        }

        return $pcm;
    }
}
