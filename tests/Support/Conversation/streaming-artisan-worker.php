<?php

use App\Services\Speech\OpenAIStreamingTextToSpeechProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\ConversationFakeTranslationProvider;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
config(['conversation.initial_pcm_bytes' => (int) ($argv[1] ?? 3840)]);
config(['benchmarks.translation.pipeline.providers.chirp3-streaming-standard-deepl-openai.translation.contract' => ConversationFakeTranslationProvider::class]);
Http::preventStrayRequests();
Http::fake(['api.openai.test/*' => fn () => Http::response(str_repeat("\x01\x02", 3840))]);
$app->instance(OpenAIStreamingTextToSpeechProvider::class,
    new OpenAIStreamingTextToSpeechProvider($app->make(Factory::class), 'fake-key', 'unchanged-model', 'https://api.openai.test/speech', 'marin'));
exit($kernel->call('conversation:run-worker', ['--persistent' => true]));
