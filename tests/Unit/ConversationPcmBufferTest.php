<?php

use App\Services\Conversation\ConversationPcmBuffer;

it('reconstructs PCM exactly with an independently sized initial packet and odd input fragments', function (int $initial): void {
    $pcm = str_repeat("\x01\x80", 8000);
    $buffer = new ConversationPcmBuffer($initial);
    $parts = [];
    foreach (str_split($pcm, 137) as $fragment) {
        array_push($parts, ...$buffer->push($fragment));
    }
    $tail = $buffer->finish();
    expect(strlen($parts[0]))->toBe($initial);
    foreach (array_slice($parts, 1) as $part) {
        expect(strlen($part))->toBe(3840);
    }
    expect(strlen($tail) % 2)->toBe(0)
        ->and(implode('', $parts).$tail)->toBe($pcm);
})->with([1920, 3840]);

it('flushes a short aligned final packet but rejects invalid PCM or configuration', function (): void {
    $buffer = new ConversationPcmBuffer(1920);
    expect($buffer->push("\x01\x02"))->toBe([])->and($buffer->finish())->toBe("\x01\x02");
    $odd = new ConversationPcmBuffer;
    $odd->push('x');
    expect(fn () => $odd->finish())->toThrow(RuntimeException::class)
        ->and(fn () => (new ConversationPcmBuffer)->finish())->toThrow(RuntimeException::class)
        ->and(fn () => new ConversationPcmBuffer(1919))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new ConversationPcmBuffer)->push(str_repeat('x', 2097153)))->toThrow(RuntimeException::class);
});
