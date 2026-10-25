<?php

namespace App\Services\Conversation;

use Closure;
use WebSocket\Connection;

/** Bounded, local-lab-only timing state; never stores tokens, transcripts or audio. */
final class ConversationLatencyRelay
{
    /** @var array<string, array<string, mixed>> */
    private array $records = [];

    /** @var array<int, array{turn: string, at: int, finished?: int}> */
    private array $stops = [];

    /** @param Closure(Connection, array): void $send */
    public function stop(Connection $owner, Connection $peer, string $turn, Closure $send): void
    {
        if (($this->stops[spl_object_id($owner)]['turn'] ?? null) === $turn) {
            return;
        }
        $this->stops[spl_object_id($owner)] = ['turn' => $turn, 'at' => hrtime(true)];
        if (count($this->stops) > 128) {
            unset($this->stops[array_key_first($this->stops)]);
        }
        $send($peer, ['type' => 'conversation_stop_received', 'conversation_turn_id' => $turn]);
    }

    /** @param array<string, mixed> $correlation */
    public function register(array $correlation, Connection $owner, Connection $peer, int $finalAt): void
    {
        $this->prune();
        $this->records[$correlation['segment_id']] = [
            'correlation' => $correlation, 'owner' => $owner, 'peer' => $peer,
            'final_at' => $finalAt, 'created_at' => hrtime(true), 'seen' => [],
            'metrics' => ['queue_wait_ms' => round((hrtime(true) - $finalAt) / 1_000_000, 3)],
        ];
        if (count($this->records) > 128) {
            array_shift($this->records);
        }
    }

    public function sttFinished(Connection $owner, ?int $at = null): void
    {
        $key = spl_object_id($owner);
        if (isset($this->stops[$key])) {
            $this->stops[$key]['finished'] = $at ?? hrtime(true);
        }
    }

    public function sent(string $id, int $begin, int $end): void
    {
        if (! isset($this->records[$id]) || isset($this->records[$id]['metrics']['socket_write_ms'])) {
            return;
        }
        $record = &$this->records[$id];
        $record['metrics']['final_to_first_pcm_send_ms'] = round(($begin - $record['final_at']) / 1_000_000, 3);
        $record['metrics']['socket_write_ms'] = round(($end - $begin) / 1_000_000, 3);
        $stop = $this->stops[spl_object_id($record['owner'])] ?? null;
        if ($stop !== null && $stop['turn'] === $record['correlation']['conversation_turn_id'] && $begin >= $stop['at']) {
            $record['metrics']['stop_to_first_pcm_send_ms'] = round(($begin - $stop['at']) / 1_000_000, 3);
        }
    }

    /** @return array<string, int|float|null> */
    public function metrics(string $id): array
    {
        $record = $this->records[$id] ?? null;
        if ($record === null) {
            return [];
        }
        $stop = $this->stops[spl_object_id($record['owner'])] ?? null;
        $metrics = $record['metrics'];
        if ($stop !== null && $stop['turn'] === $record['correlation']['conversation_turn_id'] && isset($stop['finished'])) {
            $metrics['stt_completion_ms'] = round(($stop['finished'] - $stop['at']) / 1_000_000, 3);
        }

        return ConversationTiming::durations($metrics);
    }

    /** @param array<string, mixed> $payload
     * @param  Closure(Connection, array): void  $send
     */
    public function feedback(Connection $peer, array $payload, ConversationWebSocketHandler $handler, Closure $send): void
    {
        $this->prune();
        $id = $payload['segment_id'] ?? null;
        $stage = $payload['stage'] ?? null;
        if (! is_string($id) || ! is_string($stage) || ! isset($this->records[$id])) {
            return;
        }
        $record = &$this->records[$id];
        if ($record['peer'] !== $peer || ! $handler->isCurrent($peer) || ! $handler->isCurrent($record['owner'])
            || $handler->currentPeer($record['owner']) !== $peer
            || ! in_array($stage, ['first_pcm_received', 'playback_scheduled', 'playback_started'], true)
            || isset($record['seen'][$stage])) {
            return;
        }
        foreach ($record['correlation'] as $key => $value) {
            if (($payload[$key] ?? null) !== $value) {
                return;
            }
        }
        $record['seen'][$stage] = true;
        $client = [];
        $values = $payload['client_latency'] ?? [];
        if (is_array($values)) {
            foreach (['tts_event_to_first_pcm_receipt_ms', 'receiver_stop_marker_to_first_pcm_ms', 'first_pcm_receipt_to_queue_ms', 'queue_to_scheduled_start_estimate_ms', 'first_pcm_receipt_to_playback_start_observed_ms'] as $metric) {
                $value = $values[$metric] ?? null;
                if ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 120000) {
                    $client[$metric] = $value;
                }
            }
        }
        if (ConversationTiming::enabled()) {
            logger()->info('CONVERSATION_LATENCY receiver', [...$record['correlation'], 'stage' => $stage, 'client_latency' => $client]);
        }
        if (($payload['after_stop'] ?? false) !== true) {
            return;
        }
        $send($record['owner'], ['type' => 'conversation_playback_ack', ...$record['correlation'], 'stage' => $stage, 'after_stop' => true, 'client_latency' => $client]);
    }

    public function cancel(string $id): void
    {
        unset($this->records[$id]);
    }

    public function forget(Connection $connection): void
    {
        unset($this->stops[spl_object_id($connection)]);
        foreach ($this->records as $id => $record) {
            if ($record['owner'] === $connection || $record['peer'] === $connection) {
                unset($this->records[$id]);
            }
        }
    }

    private function prune(): void
    {
        foreach ($this->records as $id => $record) {
            if (hrtime(true) - $record['created_at'] > 120_000_000_000) {
                unset($this->records[$id]);
            }
        }
    }
}
