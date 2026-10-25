<?php

// No provider calls: exercise the production packetizer with controlled input pacing.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Services\Conversation\ConversationPcmBuffer;

function statistics(array $values): array
{
    sort($values);
    $count = count($values);

    return ['count' => $count, 'median' => ($values[(int) floor(($count - 1) / 2)] + $values[(int) floor($count / 2)]) / 2,
        'p95' => $values[(int) ceil($count * .95) - 1], 'max' => max($values)];
}

$results = [];
$pcm = str_repeat("\x01\x02", 3840);
foreach (['paced_480_bytes_every_10_ms', 'burst_7680_bytes'] as $scenario) {
    foreach ([3840, 1920] as $initial) {
        $samples = [];
        $counts = [];
        for ($run = 0; $run < 30; $run++) {
            $buffer = new ConversationPcmBuffer($initial);
            $output = [];
            $first = null;
            $started = hrtime(true);
            foreach (str_split($pcm, $scenario === 'burst_7680_bytes' ? 7680 : 480) as $chunk) {
                if ($scenario !== 'burst_7680_bytes') {
                    usleep(10000);
                }
                foreach ($buffer->push($chunk) as $part) {
                    $first ??= hrtime(true);
                    if (strlen($part) % 2 !== 0) {
                        throw new RuntimeException('Misaligned frame');
                    }
                    $output[] = $part;
                }
            }
            $tail = $buffer->finish();
            if ($tail !== '') {
                $output[] = $tail;
            }
            if (implode('', $output) !== $pcm) {
                throw new RuntimeException('PCM changed');
            }
            $samples[] = round(($first - $started) / 1_000_000, 3);
            $counts[] = count($output);
        }
        $results[$scenario][(string) $initial] = ['first_emit_ms' => statistics($samples),
            'frames_per_stream' => statistics($counts), 'pcm_sha256' => hash('sha256', $pcm)];
    }
}
echo json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
