<?php

declare(strict_types=1);

$input = stream_get_contents(STDIN);
$job = json_decode($input, true, flags: JSON_THROW_ON_ERROR);

if (! is_array($job)) {
    throw new RuntimeException('Invalid worker job.');
}

$turnId = $job['turn_id'] ?? null;
$delayMs = $job['delay_ms'] ?? null;

if (
    ! is_string($turnId)
    || $turnId === ''
    || ! is_int($delayMs)
    || $delayMs < 0
    || $delayMs > 5000
) {
    throw new RuntimeException('Invalid worker arguments.');
}

usleep($delayMs * 1000);

echo json_encode([
    'turn_id' => $turnId,
    'status' => 'completed',
    'translated_text' => 'Worker completed translation.',
], JSON_THROW_ON_ERROR);
