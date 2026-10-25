<?php

use App\Services\Conversation\ConversationConnectionRegistry;
use App\Services\Conversation\ConversationLatencyRelay;
use App\Services\Conversation\ConversationStore;
use App\Services\Conversation\ConversationTiming;
use App\Services\Conversation\ConversationTurnManager;
use App\Services\Conversation\ConversationWebSocketHandler;
use WebSocket\Connection;

it('correlates stop, queue, STT, socket writes and receiver acknowledgements and rejects spoofed feedback', function (): void {
    config(['conversation.enabled' => true, 'cache.default' => 'array']);
    $store = app(ConversationStore::class);
    $host = $store->create();
    $guest = $store->join($host['code']);
    $handler = new ConversationWebSocketHandler($store, new ConversationConnectionRegistry, new ConversationTurnManager);
    $owner = Mockery::mock(Connection::class);
    $peer = Mockery::mock(Connection::class);
    $owner->shouldReceive('isConnected')->andReturn(true);
    $peer->shouldReceive('isConnected')->andReturn(true);
    $peer->shouldReceive('disconnect')->once();
    $handler->join($owner, $host['code'], $host['token'], fn () => null);
    $handler->join($peer, $guest['code'], $guest['token'], fn () => null);
    $handler->startTurn($owner, fn () => null);
    $correlation = $handler->nextSegment($owner);
    $relay = new ConversationLatencyRelay;
    $events = [];
    $send = function (Connection $to, array $event) use (&$events): void {
        $events[] = $event;
    };
    $relay->stop($owner, $peer, $correlation['conversation_turn_id'], $send);
    $relay->register($correlation, $owner, $peer, hrtime(true) - 5_000_000);
    $relay->sttFinished($owner);
    $begin = hrtime(true);
    $relay->sent($correlation['segment_id'], $begin, $begin + 1_000_000);
    $metrics = $relay->metrics($correlation['segment_id']);
    expect($metrics['queue_wait_ms'])->toBeGreaterThanOrEqual(5)
        ->and($metrics['socket_write_ms'])->toBe(1.0)
        ->and($metrics)->toHaveKeys(['stt_completion_ms', 'stop_to_first_pcm_send_ms']);
    $feedback = [...$correlation, 'stage' => 'playback_started', 'after_stop' => true, 'client_latency' => ['first_pcm_receipt_to_queue_ms' => 1.25, 'token' => 'never retained', 'tts_event_to_first_pcm_receipt_ms' => INF]];
    $relay->feedback($owner, $feedback, $handler, $send); // Sender cannot impersonate receiver.
    $relay->feedback($peer, [...$feedback, 'segment_index' => 99], $handler, $send);
    expect(count($events))->toBe(1);
    $relay->feedback($peer, $feedback, $handler, $send);
    $relay->feedback($peer, $feedback, $handler, $send);
    expect(count($events))->toBe(2)->and($events[1])->toMatchArray(['type' => 'conversation_playback_ack', ...$correlation])
        ->and($events[1]['client_latency'])->toBe(['first_pcm_receipt_to_queue_ms' => 1.25]);
    $new = Mockery::mock(Connection::class);
    $handler->join($new, $guest['code'], $guest['token'], fn () => null);
    $relay->feedback($peer, [...$feedback, 'stage' => 'first_pcm_received'], $handler, $send);
    $relay->feedback($new, [...$feedback, 'stage' => 'first_pcm_received'], $handler, $send);
    expect(count($events))->toBe(2);
    $cancelled = $handler->nextSegment($owner);
    $relay->register($cancelled, $owner, $new, hrtime(true));
    $relay->cancel($cancelled['segment_id']);
    $relay->feedback($new, [...$cancelled, 'stage' => 'playback_started', 'after_stop' => true], $handler, $send);
    expect(count($events))->toBe(2)->and($relay->metrics($cancelled['segment_id']))->toBe([]);
    $relay->forget($owner);
    expect($relay->metrics($correlation['segment_id']))->toBe([]);
});

it('allows only finite nonnegative known timing durations and is default-off', function (): void {
    config(['conversation.latency_debug_enabled' => false]);
    expect(ConversationTiming::enabled())->toBeFalse()
        ->and(ConversationTiming::durations(['translation_ms' => 0, 'total_ms' => -1, 'tts_request_ms' => 1.5, 'queue_wait_ms' => INF, 'token' => 'secret']))
        ->toBe(['translation_ms' => 0, 'tts_request_ms' => 1.5]);
});
