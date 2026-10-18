<?php

declare(strict_types=1);

$job = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$mode = $argv[1] ?? 'valid';
$events = [
    ['type' => 'translation', 'translated_text' => 'Mock translation'],
    ['type' => 'tts_started', 'mime_type' => 'audio/pcm'],
    ['type' => 'audio_chunk', 'audio_base64' => base64_encode("\x01\x02")],
    ['type' => 'audio_chunk', 'audio_base64' => base64_encode("\x03\x04")],
    [
        'type' => 'completed',
        'audio_bytes' => 4,
        'worker_latency' => [
            'startup_ms' => null,
            'translation_ms' => 12,
            'translation_to_first_pcm_ms' => 34,
            'total_ms' => 56,
            'source_text' => 'Must not escape into diagnostics',
        ],
    ],
];

if (($job['omit_latency'] ?? false) === true) {
    unset($events[4]['worker_latency']);
}

if ($mode === 'out_of_order') {
    [$events[1], $events[2]] = [$events[2], $events[1]];
} elseif ($mode === 'duplicate_completion') {
    $events[] = $events[4];
} elseif ($mode === 'wrong_size') {
    $events[4]['audio_bytes'] = 6;
} elseif ($mode === 'stale_turn') {
    $job['turn_id'] = 'stale-turn';
}

foreach ($events as $event) {
    $event['turn_id'] = $job['turn_id'];
    fwrite(STDOUT, json_encode($event, JSON_THROW_ON_ERROR)."\n");
    fflush(STDOUT);
}

exit($mode === 'failed_exit' ? 1 : 0);
