<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Services\Conversation\AsyncSttBridgeClient;

$wavPath = dirname(__DIR__, 2).'/storage/app/openai-realtime-ru-001.wav';
$audio = file_get_contents($wavPath);
if ($audio === false || substr($audio, 0, 4) !== 'RIFF'
    || substr($audio, 8, 4) !== 'WAVE'
    || substr($audio, 12, 4) !== 'fmt '
) {
    throw new RuntimeException('Expected standard PCM16 WAV file.');
}

// This existing laboratory WAV was verified as PCM16 mono 24000 Hz.
// WAV header may include variable chunks: locate the `data` chunk instead of assuming offset 44.
$sampleRate = unpack('V', substr($audio, 24, 4))[1];
$channels = unpack('v', substr($audio, 22, 2))[1];
$bitDepth = unpack('v', substr($audio, 34, 2))[1];
$format = unpack('v', substr($audio, 20, 2))[1];
if ($sampleRate !== 24000 || $channels !== 1 || $bitDepth !== 16 || $format !== 1) {
    throw new RuntimeException('Expected 24000 Hz mono PCM16 laboratory WAV.');
}

$offset = 12;
$pcm = null;
while ($offset + 8 <= strlen($audio)) {
    $id = substr($audio, $offset, 4);
    $length = unpack('V', substr($audio, $offset + 4, 4))[1];
    $begin = $offset + 8;
    if ($begin + $length > strlen($audio)) {
        throw new RuntimeException('Invalid WAV chunk length.');
    }
    if ($id === 'data') {
        $pcm = substr($audio, $begin, $length);
        break;
    }
    $offset = $begin + $length + ($length % 2);
}
if ($pcm === null || $pcm === '') {
    throw new RuntimeException('WAV data chunk not found.');
}

$host = getenv('STT_BRIDGE_HOST') ?: 'host.docker.internal';
$client = new AsyncSttBridgeClient(host: $host);
$client->start('en-US', $sampleRate);
$chunkBytes = (int) ($sampleRate * 2 * 0.1); // 100 ms PCM16 mono
$start = microtime(true);
$sent = 0;
$firstFinalBeforeStop = false;
$stopped = false;

try {
    while (true) {
        $elapsed = microtime(true) - $start;
        $targetSent = min(strlen($pcm), (int) floor($elapsed / 0.1) * $chunkBytes);
        while ($sent + $chunkBytes <= $targetSent) {
            $client->pushAudio(substr($pcm, $sent, $chunkBytes));
            $sent += $chunkBytes;
        }
        if (! $stopped && $sent + $chunkBytes > strlen($pcm)) {
            if ($sent < strlen($pcm)) {
                $client->pushAudio(substr($pcm, $sent));
                $sent = strlen($pcm);
            }
            $client->finish();
            $stopped = true;
            printf("[%.3fs] STOP sent\n", microtime(true) - $start);
        }
        foreach ($client->poll() as $event) {
            if ($event['type'] === 'final' && ! $stopped) {
                $firstFinalBeforeStop = true;
            }
            if ($event['type'] !== 'interim') {
                printf("[%.3fs] %s\n", microtime(true) - $start, json_encode($event, JSON_UNESCAPED_UNICODE));
            }
        }
        if ($client->isDone()) {
            break;
        }
        if ($elapsed > 35) {
            throw new RuntimeException('STT bridge smoke test timeout.');
        }
        usleep(10000);
    }
} finally {
    $client->close();
}

printf("RESULT: final_before_stop=%s\n", $firstFinalBeforeStop ? 'true' : 'false');
if (! $firstFinalBeforeStop) {
    exit(1);
}
