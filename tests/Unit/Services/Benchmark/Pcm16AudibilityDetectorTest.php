<?php

use App\Services\Benchmark\Pcm16AudibilityDetector;

function pcm16Frame(
    int $sampleValue,
    int $samples = 240,
): string {
    $value = $sampleValue;

    if ($value < 0) {
        $value += 0x10000;
    }

    $sample = pack(
        'v',
        $value,
    );

    return str_repeat(
        $sample,
        $samples,
    );
}

test('it detects audible pcm after leading silence', function () {
    $detector = new Pcm16AudibilityDetector;

    $silence = pcm16Frame(0);
    $audible = pcm16Frame(1000);

    expect($detector->consume($silence))
        ->toBeNull()
        ->and($detector->consume($audible))
        ->toBe(10.0);
});

test('it handles pcm frames split across arbitrary chunks', function () {
    $detector = new Pcm16AudibilityDetector;

    $silence = pcm16Frame(0);
    $audible = pcm16Frame(1000);

    $audio = $silence.$audible;

    expect(
        $detector->consume(
            substr($audio, 0, 317),
        ),
    )->toBeNull();

    expect(
        $detector->consume(
            substr($audio, 317, 401),
        ),
    )->toBeNull();

    expect(
        $detector->consume(
            substr($audio, 718),
        ),
    )->toBe(10.0);
});

test('it does not classify quiet pcm as audible', function () {
    $detector = new Pcm16AudibilityDetector;

    expect(
        $detector->consume(
            pcm16Frame(100),
        ),
    )->toBeNull();
});

test('it detects audible pcm in the first frame', function () {
    $detector = new Pcm16AudibilityDetector;

    expect(
        $detector->consume(
            pcm16Frame(1000),
        ),
    )->toBe(0.0);
});

test('it reports audible audio only once', function () {
    $detector = new Pcm16AudibilityDetector;

    expect(
        $detector->consume(
            pcm16Frame(1000),
        ),
    )->toBe(0.0);

    expect(
        $detector->consume(
            pcm16Frame(1000),
        ),
    )->toBeNull();
});
