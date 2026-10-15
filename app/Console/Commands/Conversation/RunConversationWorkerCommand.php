<?php

namespace App\Console\Commands\Conversation;

use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class RunConversationWorkerCommand extends Command
{
    protected $signature = 'conversation:run-worker';

    protected $description = 'Process a conversation pipeline job';

    public function handle(): int
    {
        try {
            $input = stream_get_contents(STDIN);

            if ($input === false) {
                throw new RuntimeException('Unable to read worker input.');
            }

            $job = json_decode(
                $input,
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            if (
                ! is_array($job)
                || ! is_string($job['turn_id'] ?? null)
                || $job['turn_id'] === ''
                || ! is_string($job['source_text'] ?? null)
            ) {
                throw new RuntimeException('Invalid worker job.');
            }

            $result = [
                'turn_id' => $job['turn_id'],
                'status' => 'completed',
                'translated_text' => $job['source_text'],
                'audio_base64' => base64_encode(''),
                'mime_type' => 'audio/pcm',
            ];

            fwrite(
                STDOUT,
                json_encode($result, JSON_THROW_ON_ERROR),
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception->getMessage().PHP_EOL);

            return self::FAILURE;
        }
    }
}
