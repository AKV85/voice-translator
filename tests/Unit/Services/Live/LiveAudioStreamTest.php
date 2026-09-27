<?php

use App\Services\Live\LiveAudioStream;
use Fiber;
use LogicException;

it('streams pushed audio chunks through a suspended fiber until finished', function () {
    $stream = new LiveAudioStream;

    $received = [];

    $fiber = new Fiber(
        function () use (
            $stream,
            &$received,
        ): void {
            foreach ($stream->chunks() as $chunk) {
                $received[] = $chunk;
            }
        },
    );

    $fiber->start();

    expect($fiber->isSuspended())
        ->toBeTrue()
        ->and($received)
        ->toBe([]);

    $stream->push('first-chunk');

    expect($fiber->isSuspended())
        ->toBeTrue()
        ->and($received)
        ->toBe([
            'first-chunk',
        ]);

    $stream->push('second-chunk');

    expect($fiber->isSuspended())
        ->toBeTrue()
        ->and($received)
        ->toBe([
            'first-chunk',
            'second-chunk',
        ]);

    $stream->finish();

    expect($fiber->isTerminated())
        ->toBeTrue()
        ->and($stream->isFinished())
        ->toBeTrue()
        ->and($received)
        ->toBe([
            'first-chunk',
            'second-chunk',
        ]);
});

it('ignores empty audio chunks', function () {
    $stream = new LiveAudioStream;

    $received = [];

    $fiber = new Fiber(
        function () use (
            $stream,
            &$received,
        ): void {
            foreach ($stream->chunks() as $chunk) {
                $received[] = $chunk;
            }
        },
    );

    $fiber->start();

    $stream->push('');

    expect($fiber->isSuspended())
        ->toBeTrue()
        ->and($received)
        ->toBe([]);

    $stream->finish();

    expect($fiber->isTerminated())
        ->toBeTrue();
});

it('rejects audio pushed after the stream has finished', function () {
    $stream = new LiveAudioStream;

    $stream->finish();

    expect(
        fn () => $stream->push('late-chunk'),
    )->toThrow(
        LogicException::class,
        'Cannot push audio after the live audio stream has finished.',
    );
});

it('requires live audio consumption to run inside a fiber', function () {
    $stream = new LiveAudioStream;

    expect(
        fn () => iterator_to_array(
            $stream->chunks(),
        ),
    )->toThrow(
        LogicException::class,
        'Live audio stream must be consumed inside a Fiber.',
    );
});
