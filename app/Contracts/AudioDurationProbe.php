<?php

namespace App\Contracts;

interface AudioDurationProbe
{
    public function durationMs(string $audioPath): float;
}
