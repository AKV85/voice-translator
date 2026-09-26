<?php

namespace App\Services\Benchmark;

final readonly class BenchmarkTextNormalizer
{
    public function normalize(string $text): string
    {
        $text = mb_strtolower(
            trim($text),
        );

        $text = str_replace(
            'ё',
            'е',
            $text,
        );

        $text = preg_replace(
            '/[^\p{L}\p{N}:°]+/u',
            ' ',
            $text,
        ) ?? '';

        return preg_replace(
            '/\s+/u',
            ' ',
            trim($text),
        ) ?? '';
    }
}
