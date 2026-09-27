<?php

use App\Models\LivePipelineRun;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores live pipeline run data', function (): void {
    $run =
        LivePipelineRun::query()->create([
            'comparison_id' => '550e8400-e29b-41d4-a716-446655440000',

            'comparison_round' => 2,

            'pipeline_profile' => 'chirp3-streaming-standard-deepl-openai',

            'source_language' => 'ru',

            'target_language' => 'en',

            'input_audio_duration_ms' => 4215.37,

            'stt_transcript' => 'Кот Персик рыжий пушистый хулиган.',

            'translated_text' => 'Peach the cat is a fluffy ginger rascal.',

            'success' => true,

            'metrics' => [
                'server_stop_to_stt_ms' => 645.33,

                'translation_ms' => 182.44,

                'browser_input_end_to_first_audible_audio_received_ms' => 1312.10,
            ],
        ]);

    $run->refresh();

    expect($run->comparison_round)
        ->toBe(2)
        ->and($run->pipeline_profile)
        ->toBe(
            'chirp3-streaming-standard-deepl-openai',
        )
        ->and($run->source_language)
        ->toBe('ru')
        ->and($run->target_language)
        ->toBe('en')
        ->and($run->input_audio_duration_ms)
        ->toBe(4215.37)
        ->and($run->success)
        ->toBeTrue()
        ->and($run->metrics)
        ->toEqual([
            'server_stop_to_stt_ms' => 645.33,

            'translation_ms' => 182.44,

            'browser_input_end_to_first_audible_audio_received_ms' => 1312.10,
        ]);
});

it('stores failure diagnostics', function (): void {
    $run =
        LivePipelineRun::query()->create([
            'pipeline_profile' => 'flux-deepl-openai',

            'source_language' => 'ru',

            'target_language' => 'en',

            'success' => false,

            'failed_stage' => 'stt',

            'error_message' => 'Speech transcription failed.',
        ]);

    $run->refresh();

    expect($run->success)
        ->toBeFalse()
        ->and($run->failed_stage)
        ->toBe('stt')
        ->and($run->error_message)
        ->toBe(
            'Speech transcription failed.',
        );
});

it('stores manual quality review data', function (): void {
    $run =
        LivePipelineRun::query()->create([
            'pipeline_profile' => 'chirp3-streaming-short-deepl-openai',

            'source_language' => 'ru',

            'target_language' => 'en',

            'success' => true,

            'quality_rating' => 'wrong',

            'quality_issues' => [
                'stt_wrong',
                'meaning_changed',
            ],

            'reviewed_at' => now(),
        ]);

    $run->refresh();

    expect($run->quality_rating)
        ->toBe('wrong')
        ->and($run->quality_issues)
        ->toBe([
            'stt_wrong',
            'meaning_changed',
        ])
        ->and($run->reviewed_at)
        ->not->toBeNull();
});
