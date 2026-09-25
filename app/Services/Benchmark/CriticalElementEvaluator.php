<?php

namespace App\Services\Benchmark;

final readonly class CriticalElementEvaluator
{
    public function __construct(
        private BenchmarkTextNormalizer $normalizer,
    ) {}

    /**
     * @param  array<int, mixed>  $criticalElements
     * @return array{
     *     ok: bool|null,
     *     passed: int,
     *     total: int,
     *     missing_critical_elements: list<string>
     * }
     */
    public function evaluate(
        mixed $actual,
        array $criticalElements,
        bool $successful,
    ): array {
        if (
            ! $successful
            || ! is_string($actual)
        ) {
            return [
                'ok' => null,
                'passed' => 0,
                'total' => 0,
                'missing_critical_elements' => [],
            ];
        }

        $normalizedActual =
            $this->normalizer->normalize(
                $actual,
            );

        $passed = 0;
        $total = 0;
        $missing = [];

        foreach ($criticalElements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $name =
                $element['name']
                ?? null;

            $accepted =
                $element['accepted']
                ?? null;

            if (
                ! is_string($name)
                || ! is_array($accepted)
            ) {
                continue;
            }

            $acceptedValues = array_values(
                array_filter(
                    $accepted,
                    static fn (mixed $value): bool => is_string($value)
                        && $value !== '',
                ),
            );

            if ($acceptedValues === []) {
                continue;
            }

            $total++;

            $matched = false;

            foreach ($acceptedValues as $acceptedValue) {
                if (
                    $this->containsAcceptedValue(
                        normalizedActual: $normalizedActual,
                        accepted: $acceptedValue,
                    )
                ) {
                    $matched = true;

                    break;
                }
            }

            if ($matched) {
                $passed++;

                continue;
            }

            $missing[] = $name;
        }

        return [
            'ok' => $total > 0
                ? $passed === $total
                : null,

            'passed' => $passed,

            'total' => $total,

            'missing_critical_elements' => $missing,
        ];
    }

    private function containsAcceptedValue(
        string $normalizedActual,
        string $accepted,
    ): bool {
        $normalizedAccepted =
            $this->normalizer->normalize(
                $accepted,
            );

        if ($normalizedAccepted === '') {
            return false;
        }

        $pattern = sprintf(
            '/(?<![\p{L}\p{N}])%s(?![\p{L}\p{N}])/u',
            preg_quote(
                $normalizedAccepted,
                '/',
            ),
        );

        return preg_match(
            $pattern,
            $normalizedActual,
        ) === 1;
    }
}
