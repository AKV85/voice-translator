<?php

namespace App\Services\Conversation;

use RuntimeException;
use Throwable;

/**
 * Two prestarted Laravel CLI workers. Each worker handles multiple JSONL jobs.
 * This is intentionally opt-in: legacy one-shot worker tests remain unchanged.
 */
final class ConversationPersistentWorkerPool
{
    private const WORKERS = 2;

    private const MAX_AUDIO_BYTES = 2097152;

    private const MAX_LINE_BYTES = 16384;

    /** @var array<int, array<string, mixed>> */
    private array $slots = [];

    /** @var array<string, int> */
    private array $turnSlots = [];

    public function __construct(
        private readonly array $command,
        private readonly float $timeoutSeconds,
    ) {
        for ($i = 0; $i < self::WORKERS; $i++) {
            $this->slots[$i] = $this->spawn();
        }
    }

    public function __destruct()
    {
        foreach ($this->slots as $slot) {
            $this->stop($slot);
        }
    }

    /** @param array<string, mixed> $job */
    public function startJob(string $turnId, array $job): void
    {
        if ($turnId === '' || isset($this->turnSlots[$turnId])) {
            throw new RuntimeException('Invalid or duplicate conversation turn.');
        }

        $available = null;
        foreach ($this->slots as $index => $slot) {
            if ($slot['turn_id'] === null) {
                $available = $index;
                break;
            }
        }

        if ($available === null) {
            throw new RuntimeException('Conversation worker capacity reached.');
        }

        $this->ensureRunning($available);
        $slot = &$this->slots[$available];
        $line = json_encode($job, JSON_THROW_ON_ERROR)."\n";
        $length = strlen($line);
        $written = 0;

        // The job is small (< 9 KB); no data is read until the worker is ready.
        while ($written < $length) {
            $n = fwrite($slot['pipes'][0], substr($line, $written));
            if ($n === false || $n === 0) {
                $this->restart($available);
                throw new RuntimeException('Unable to send conversation worker job.');
            }
            $written += $n;
        }
        fflush($slot['pipes'][0]);

        $slot['turn_id'] = $turnId;
        $slot['started_at'] = microtime(true);
        $slot['phase'] = 'new';
        $slot['bytes'] = 0;
        $slot['buffer'] = '';
        $this->turnSlots[$turnId] = $available;
    }

    /** @return list<array<string, mixed>> */
    public function pollStream(string $turnId): array
    {
        $index = $this->turnSlots[$turnId] ?? null;
        if ($index === null) {
            return [];
        }

        try {
            $slot = &$this->slots[$index];
            if (microtime(true) - $slot['started_at'] > $this->timeoutSeconds) {
                throw new RuntimeException('Conversation worker timed out.');
            }

            $chunk = stream_get_contents($slot['pipes'][1]);
            if ($chunk === false) {
                throw new RuntimeException('Unable to read conversation worker output.');
            }
            $slot['buffer'] .= $chunk;
            $events = [];

            while (($pos = strpos($slot['buffer'], "\n")) !== false) {
                $line = substr($slot['buffer'], 0, $pos);
                $slot['buffer'] = substr($slot['buffer'], $pos + 1);
                if ($line === '' || strlen($line) > self::MAX_LINE_BYTES) {
                    throw new RuntimeException('Invalid conversation stream event length.');
                }

                $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($event) || ($event['turn_id'] ?? null) !== $turnId) {
                    throw new RuntimeException('Invalid conversation stream turn ID.');
                }

                $type = $event['type'] ?? null;
                if ($type === 'error') {
                    throw new RuntimeException('Persistent conversation worker failed.');
                }

                $expected = match ($slot['phase']) {
                    'new' => ['translation'],
                    'translated' => ['tts_started'],
                    'audio' => ['audio_chunk', 'completed'],
                    default => [],
                };
                if (! in_array($type, $expected, true)) {
                    throw new RuntimeException('Invalid conversation stream event order.');
                }

                if ($type === 'translation') {
                    if (! is_string($event['translated_text'] ?? null)) {
                        throw new RuntimeException('Invalid translation event.');
                    }
                    $slot['phase'] = 'translated';
                } elseif ($type === 'tts_started') {
                    if (($event['mime_type'] ?? null) !== 'audio/pcm') {
                        throw new RuntimeException('Invalid TTS format.');
                    }
                    $slot['phase'] = 'audio';
                } elseif ($type === 'audio_chunk') {
                    $encoded = $event['audio_base64'] ?? null;
                    $audio = is_string($encoded) ? base64_decode($encoded, true) : false;
                    if ($audio === false || $audio === '' || strlen($audio) % 2 !== 0) {
                        throw new RuntimeException('Invalid PCM16 chunk.');
                    }
                    $slot['bytes'] += strlen($audio);
                    if ($slot['bytes'] > self::MAX_AUDIO_BYTES) {
                        throw new RuntimeException('Conversation audio limit exceeded.');
                    }
                    unset($event['audio_base64']);
                    $event['audio'] = $audio;
                } elseif ($type === 'completed') {
                    if (! is_int($event['audio_bytes'] ?? null)
                        || $event['audio_bytes'] !== $slot['bytes']) {
                        throw new RuntimeException('Invalid conversation audio completion.');
                    }
                    $slot['phase'] = 'completed';
                }

                $events[] = $event;
            }

            if (strlen($slot['buffer']) > self::MAX_LINE_BYTES) {
                throw new RuntimeException('Conversation stream buffer exceeded.');
            }

            $status = proc_get_status($slot['process']);
            if (! $status['running']) {
                throw new RuntimeException('Persistent conversation worker exited unexpectedly.');
            }

            if ($slot['phase'] === 'completed') {
                $slot['turn_id'] = null;
                $slot['started_at'] = null;
                unset($this->turnSlots[$turnId]);
            }

            return $events;
        } catch (Throwable $exception) {
            $this->cancel($turnId);
            throw $exception;
        }
    }

    public function cancel(string $turnId): void
    {
        $index = $this->turnSlots[$turnId] ?? null;
        if ($index === null) {
            return;
        }
        unset($this->turnSlots[$turnId]);
        // Cancel active DeepL/TTS by killing only the affected worker.
        $this->restart($index);
    }

    public function has(string $turnId): bool
    {
        return isset($this->turnSlots[$turnId]);
    }

    /** @return array<string, mixed> */
    private function spawn(): array
    {
        $command = [...$this->command, '--persistent'];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start persistent conversation worker.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'process' => $process,
            'pipes' => $pipes,
            'turn_id' => null,
            'started_at' => null,
            'phase' => 'new',
            'bytes' => 0,
            'buffer' => '',
        ];
    }

    private function ensureRunning(int $index): void
    {
        $status = proc_get_status($this->slots[$index]['process']);
        if (! $status['running']) {
            $this->restart($index);
        }
    }

    private function restart(int $index): void
    {
        $this->stop($this->slots[$index]);
        $this->slots[$index] = $this->spawn();
    }

    /** @param array<string, mixed> $slot */
    private function stop(array $slot): void
    {
        if (is_resource($slot['process'])) {
            $status = proc_get_status($slot['process']);
            if ($status['running']) {
                proc_terminate($slot['process'], 9);
            }
        }
        foreach ($slot['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($slot['process'])) {
            proc_close($slot['process']);
        }
    }
}
