<?php

use App\Services\Conversation\ConversationSegmentSequence;

it('assigns opaque unique segment IDs with one turn identity and increasing order', function (): void {
    $sequence = new ConversationSegmentSequence;
    $first = $sequence->next();
    $second = $sequence->next();
    $otherTurn = new ConversationSegmentSequence;

    expect($first['conversation_turn_id'])->toBe($sequence->turnId())
        ->and($second['conversation_turn_id'])->toBe($sequence->turnId())
        ->and($first['segment_id'])->toMatch('/^[a-f0-9]{32}$/')
        ->and($second['segment_id'])->toMatch('/^[a-f0-9]{32}$/')
        ->and($first['segment_id'])->not->toBe($second['segment_id'])
        ->and($first['segment_index'])->toBe(1)
        ->and($second['segment_index'])->toBe(2)
        ->and($otherTurn->turnId())->not->toBe($sequence->turnId());
});
