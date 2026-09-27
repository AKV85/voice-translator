<?php

use App\Models\LivePipelineRun;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores a successful live pipeline run', function (): void {
    $response =
        $this->postJson(
            route('live.runs.store'),
            [
                'comparison_id' => '550e8400-e29b-41d4-a716-446655440000',

                'comparison_round' => 3,

                'pipeline_profile' => 'chirp3-streaming-standard-deepl-openai',

                'source_language' => 'ru',

                'input_audio_duration_ms' => 4821.37,

                'stt_transcript' => 'Кот Персик рыжий пушистый хулиган.',

                'translated_text' => 'Peach the cat is a fluffy ginger rascal.',

                'success' => true,

                'metrics' => [
                    'server_stop_to_stt_ms' => 669.37,

                    'server_stop_to_translation_ms' => 881.92,

                    'browser_input_end_to_first_audible_audio_received_ms' => 1764.45,
                ],
            ],
        );

    $response
        ->assertCreated()
        ->assertJsonStructure([
            'id',
        ]);

    $run =
        LivePipelineRun::query()
            ->sole();

    expect($run->comparison_id)
        ->toBe(
            '550e8400-e29b-41d4-a716-446655440000',
        )
        ->and($run->comparison_round)
        ->toBe(3)
        ->and($run->pipeline_profile)
        ->toBe(
            'chirp3-streaming-standard-deepl-openai',
        )
        ->and($run->source_language)
        ->toBe('ru')
        ->and($run->target_language)
        ->toBe('en')
        ->and($run->input_audio_duration_ms)
        ->toBe(4821.37)
        ->and($run->success)
        ->toBeTrue()
        ->and($run->stt_transcript)
        ->toBe(
            'Кот Персик рыжий пушистый хулиган.',
        )
        ->and($run->metrics)
        ->toEqual([
            'server_stop_to_stt_ms' => 669.37,

            'server_stop_to_translation_ms' => 881.92,

            'browser_input_end_to_first_audible_audio_received_ms' => 1764.45,
        ]);
});

it('stores a failed live pipeline run', function (): void {
    $response =
        $this->postJson(
            route('live.runs.store'),
            [
                'pipeline_profile' => 'flux-deepl-openai',

                'source_language' => 'en',

                'success' => false,

                'failed_stage' => 'stt',

                'error_message' => 'Speech transcription failed.',
            ],
        );

    $response->assertCreated();

    $run =
        LivePipelineRun::query()
            ->sole();

    expect($run->source_language)
        ->toBe('en')
        ->and($run->target_language)
        ->toBe('ru')
        ->and($run->success)
        ->toBeFalse()
        ->and($run->failed_stage)
        ->toBe('stt')
        ->and($run->error_message)
        ->toBe(
            'Speech transcription failed.',
        );
});

it('rejects an unsupported source language', function (): void {
    $response =
        $this->postJson(
            route('live.runs.store'),
            [
                'pipeline_profile' => 'chirp3-deepl-openai',

                'source_language' => 'lt',

                'success' => true,
            ],
        );

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'source_language',
        ]);

    expect(
        LivePipelineRun::query()->count(),
    )->toBe(0);
});
