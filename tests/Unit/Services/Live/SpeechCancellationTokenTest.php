<?php

use App\Services\Live\SpeechCancellationToken;

it('invokes cancellation callback exactly once', function () {
    $token = new SpeechCancellationToken;
    $calls = 0;

    $token->onCancel(function () use (&$calls): void {
        $calls++;
    });

    $token->cancel();
    $token->cancel();

    expect($calls)->toBe(1)
        ->and($token->isCancelled())->toBeTrue();
});

it('immediately invokes callbacks registered after cancellation', function () {
    $token = new SpeechCancellationToken;
    $calls = 0;

    $token->cancel();

    $token->onCancel(function () use (&$calls): void {
        $calls++;
    });

    expect($calls)->toBe(1);
});

it('does not invoke a cleared cancellation callback', function () {
    $token = new SpeechCancellationToken;
    $calls = 0;

    $token->onCancel(function () use (&$calls): void {
        $calls++;
    });

    $token->clear();
    $token->cancel();

    expect($calls)->toBe(0);
});

it('remains cancelled when cancellation callback throws', function () {
    $token = new SpeechCancellationToken;

    $token->onCancel(
        static function (): void {
            throw new RuntimeException('RPC cancellation failed.');
        },
    );

    expect(fn () => $token->cancel())
        ->toThrow(RuntimeException::class, 'RPC cancellation failed.');

    expect($token->isCancelled())->toBeTrue();

    // Repeated cancellation must not invoke the callback again.
    $token->cancel();
});
