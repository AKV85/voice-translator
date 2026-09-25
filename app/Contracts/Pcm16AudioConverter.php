<?php

namespace App\Contracts;

interface Pcm16AudioConverter
{
    public function convert(
        string $audioPath,
        int $sampleRate,
        int $channels,
    ): string;
}
