<?php

use Illuminate\Contracts\Console\Kernel;
use Tests\Fakes\ConversationFakeTextToSpeechProvider;
use Tests\Fakes\ConversationFakeTranslationProvider;
use Tests\Fakes\ConversationRecordingSpeechToTextProvider;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$port = (int) ($argv[1] ?? 0);
$cachePath = $argv[2] ?? '';

if ($port < 1 || $cachePath === '' || ! is_dir($cachePath)) {
    fwrite(STDERR, "Invalid integration test configuration.\n");
    exit(1);
}

config()->set('conversation.enabled', true);
config()->set('cache.default', 'vt21_integration');
config()->set('cache.stores.vt21_integration', [
    'driver' => 'file',
    'path' => $cachePath,
    'lock_path' => $cachePath,
]);

config()->set('conversation.audio_enabled', true);
config()->set('conversation.async_worker_enabled', false);
config()->set('conversation.incremental_stt_enabled', false);

config()->set(
    'conversation.integration_failure_file',
    $cachePath.'/failure-mode.txt',
);
config()->set(
    'conversation.integration_audio_log',
    $cachePath.'/received-audio.bin',
);

config()->set(
    'benchmarks.translation.pipeline.providers.'
        .'chirp3-streaming-standard-deepl-openai.speech_to_text.contract',
    ConversationRecordingSpeechToTextProvider::class,
);

config()->set(
    'benchmarks.translation.pipeline.providers.'
        .'chirp3-streaming-standard-deepl-openai.translation.contract',
    ConversationFakeTranslationProvider::class,
);

config()->set(
    'benchmarks.translation.pipeline.providers.'
        .'chirp3-streaming-standard-deepl-openai.text_to_speech.contract',
    ConversationFakeTextToSpeechProvider::class,
);

if (($argv[3] ?? null) === 'async') {
    config()->set('conversation.async_worker_enabled', true);
    config()->set('conversation.async_worker_timeout_seconds', 10);
    config()->set('conversation.async_worker_command', [
        PHP_BINARY,
        base_path('tests/Support/Conversation/async-pipeline-worker.php'),
    ]);
}

exit($kernel->call('live-pipeline:serve', [
    '--port' => $port,
]));
