<?php

use App\Services\Benchmark\SpeechTranscriptNormalizer;

it('normalizes case and punctuation', function () {
    $normalizer = new SpeechTranscriptNormalizer;

    expect(
        $normalizer->normalize(
            'Hello, DRIVER!',
        )
    )->toBe('hello driver');
});

it('normalizes English numbers', function () {
    $normalizer = new SpeechTranscriptNormalizer;

    expect(
        $normalizer->normalize(
            'one hundred and fifty-four kilometers',
        )
    )->toBe('154 km');
});

it('normalizes Russian numbers', function () {
    $normalizer = new SpeechTranscriptNormalizer;

    expect(
        $normalizer->normalize(
            'сто пятьдесят четыре километра',
        )
    )->toBe('154 км');
});

it('normalizes time representations', function () {
    $normalizer = new SpeechTranscriptNormalizer;

    expect(
        $normalizer->normalize(
            'Loading starts at seven thirty',
        )
    )->toBe('loading starts at 7 30');

    expect(
        $normalizer->normalize(
            'Loading starts at 7:30',
        )
    )->toBe('loading starts at 7 30');
});

it('does not merge spoken Russian time into one number', function () {
    $normalizer = new SpeechTranscriptNormalizer;

    expect(
        $normalizer->normalize(
            'Загрузка начнётся завтра в семь тридцать утра.',
        )
    )->toBe(
        'загрузка начнется завтра в 7 30 утра'
    );
});

it('normalizes full hour representations', function () {
    $normalizer = new SpeechTranscriptNormalizer;

    expect(
        $normalizer->normalize(
            'before eight in the evening',
        )
    )->toBe('before 8 in the evening');

    expect(
        $normalizer->normalize(
            'before 8:00 in the evening',
        )
    )->toBe('before 8 in the evening');
});

it('normalizes temperature units', function () {
    $normalizer = new SpeechTranscriptNormalizer;

    expect(
        $normalizer->normalize(
            'between two and six degrees',
        )
    )->toBe('between 2 and 6 degree');

    expect(
        $normalizer->normalize(
            'between 2 and 6°',
        )
    )->toBe('between 2 and 6 degree');
});

it('does not merge separate numbers joined by and', function () {
    $normalizer = new SpeechTranscriptNormalizer;

    expect(
        $normalizer->normalize(
            'between two and six degrees',
        )
    )->toBe('between 2 and 6 degree');
});
