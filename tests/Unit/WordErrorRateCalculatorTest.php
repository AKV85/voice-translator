<?php

use App\Services\Benchmark\WordErrorRateCalculator;

it('returns zero for identical transcripts', function () {
    $calculator = new WordErrorRateCalculator;

    expect(
        $calculator->calculate(
            'the driver is ready',
            'the driver is ready',
        )
    )->toBe(0.0);
});

it('calculates substitutions', function () {
    $calculator = new WordErrorRateCalculator;

    expect(
        $calculator->calculate(
            'the truck is ready',
            'the track is ready',
        )
    )->toBe(0.25);
});

it('calculates insertions and deletions', function () {
    $calculator = new WordErrorRateCalculator;

    $wer = $calculator->calculate(
        'one two three',
        'one three',
    );

    expect(round($wer, 4))->toBe(0.3333);
});
