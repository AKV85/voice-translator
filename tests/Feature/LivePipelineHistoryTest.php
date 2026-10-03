<?php

use App\Models\LivePipelineRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'live_pipeline.lab_enabled' => true,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('returns live pipeline runs newest first', function () {
    Carbon::setTestNow(
        '2026-09-26 10:00:00',
    );

    $older =
        LivePipelineRun::query()->create([
            'pipeline_profile' => 'chirp3-streaming-standard-deepl-openai',

            'source_language' => 'ru',
            'target_language' => 'en',

            'stt_transcript' => 'Первый запуск.',

            'translated_text' => 'First run.',

            'success' => true,
        ]);

    Carbon::setTestNow(
        '2026-09-27 10:00:00',
    );

    $newer =
        LivePipelineRun::query()->create([
            'pipeline_profile' => 'chirp3-streaming-short-deepl-openai',

            'source_language' => 'en',
            'target_language' => 'ru',

            'stt_transcript' => 'Second run.',

            'translated_text' => 'Второй запуск.',

            'success' => true,
        ]);

    $response =
        $this->getJson(
            route(
                'live.history.runs.index',
            ),
        );

    $response
        ->assertOk()
        ->assertJsonPath(
            'total',
            2,
        )
        ->assertJsonPath(
            'data.0.id',
            $newer->id,
        )
        ->assertJsonPath(
            'data.1.id',
            $older->id,
        );
});

it('filters history by direction pipeline quality and date', function () {
    Carbon::setTestNow(
        '2026-09-25 10:00:00',
    );

    $expected =
        LivePipelineRun::query()->create([
            'pipeline_profile' => 'chirp3-streaming-standard-deepl-openai',

            'source_language' => 'ru',
            'target_language' => 'en',

            'stt_transcript' => 'Нужный запуск.',

            'translated_text' => 'Expected run.',

            'success' => true,

            'quality_rating' => 'wrong',

            'quality_issues' => [
                'translation_wrong',
            ],

            'reviewed_at' => now(),
        ]);

    LivePipelineRun::query()->create([
        'pipeline_profile' => 'chirp3-streaming-short-deepl-openai',

        'source_language' => 'ru',
        'target_language' => 'en',

        'success' => true,

        'quality_rating' => 'wrong',

        'reviewed_at' => now(),
    ]);

    Carbon::setTestNow(
        '2026-09-27 10:00:00',
    );

    LivePipelineRun::query()->create([
        'pipeline_profile' => 'chirp3-streaming-standard-deepl-openai',

        'source_language' => 'en',
        'target_language' => 'ru',

        'success' => true,

        'quality_rating' => 'wrong',

        'reviewed_at' => now(),
    ]);

    $response =
        $this->getJson(
            route(
                'live.history.runs.index',
                [
                    'direction' => 'ru-en',

                    'pipeline' => 'chirp3-streaming-standard-deepl-openai',

                    'quality' => 'wrong',

                    'date_from' => '2026-09-25',

                    'date_to' => '2026-09-25',
                ],
            ),
        );

    $response
        ->assertOk()
        ->assertJsonPath(
            'total',
            1,
        )
        ->assertJsonPath(
            'data.0.id',
            $expected->id,
        );
});

it('filters unreviewed runs', function () {
    $unreviewed =
        LivePipelineRun::query()->create([
            'pipeline_profile' => 'flux-deepl-openai',

            'source_language' => 'ru',
            'target_language' => 'en',

            'success' => true,
        ]);

    LivePipelineRun::query()->create([
        'pipeline_profile' => 'flux-deepl-openai',

        'source_language' => 'ru',
        'target_language' => 'en',

        'success' => true,

        'quality_rating' => 'correct',

        'reviewed_at' => now(),
    ]);

    $response =
        $this->getJson(
            route(
                'live.history.runs.index',
                [
                    'quality' => 'unreviewed',
                ],
            ),
        );

    $response
        ->assertOk()
        ->assertJsonPath(
            'total',
            1,
        )
        ->assertJsonPath(
            'data.0.id',
            $unreviewed->id,
        );
});

it('rejects invalid history filters', function () {
    $response =
        $this->getJson(
            route(
                'live.history.runs.index',
                [
                    'direction' => 'lt-mars',

                    'quality' => 'magnificent',

                    'date_from' => 'yesterday-ish',
                ],
            ),
        );

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'direction',
            'quality',
            'date_from',
        ]);
});
