<?php

use App\Providers\AppServiceProvider;
use App\Providers\DeepgramFluxServiceProvider;
use App\Providers\DeepLTranslationServiceProvider;
use App\Providers\GoogleTranslationServiceProvider;
use App\Providers\OpenAIRealtimeTranslationServiceProvider;
use App\Providers\OpenAITranslationServiceProvider;

return [
    AppServiceProvider::class,
    DeepgramFluxServiceProvider::class,
    GoogleTranslationServiceProvider::class,
    DeepLTranslationServiceProvider::class,
    OpenAITranslationServiceProvider::class,
    OpenAIRealtimeTranslationServiceProvider::class,
];
