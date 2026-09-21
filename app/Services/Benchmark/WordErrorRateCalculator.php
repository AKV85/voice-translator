<?php

namespace App\Services\Benchmark;

class WordErrorRateCalculator
{
    public function calculate(string $expected, string $actual): float
    {
        $expectedWords = $this->words($expected);
        $actualWords = $this->words($actual);

        if ($expectedWords === []) {
            return $actualWords === [] ? 0.0 : 1.0;
        }

        $distance = $this->levenshteinDistance(
            $expectedWords,
            $actualWords,
        );

        return $distance / count($expectedWords);
    }

    /**
     * @return array<int, string>
     */
    private function words(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        return preg_split(
            '/\s+/u',
            $text,
            flags: PREG_SPLIT_NO_EMPTY,
        ) ?: [];
    }

    /**
     * @param  array<int, string>  $expected
     * @param  array<int, string>  $actual
     */
    private function levenshteinDistance(
        array $expected,
        array $actual,
    ): int {
        $previous = range(0, count($actual));

        foreach ($expected as $expectedIndex => $expectedWord) {
            $current = [$expectedIndex + 1];

            foreach ($actual as $actualIndex => $actualWord) {
                $insertion = $current[$actualIndex] + 1;
                $deletion = $previous[$actualIndex + 1] + 1;
                $substitution = $previous[$actualIndex]
                    + ($expectedWord === $actualWord ? 0 : 1);

                $current[] = min(
                    $insertion,
                    $deletion,
                    $substitution,
                );
            }

            $previous = $current;
        }

        return $previous[count($actual)];
    }
}
