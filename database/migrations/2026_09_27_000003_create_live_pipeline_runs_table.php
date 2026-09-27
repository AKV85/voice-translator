<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'live_pipeline_runs',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->uuid('comparison_id')
                    ->nullable()
                    ->index();

                $table
                    ->unsignedSmallInteger('comparison_round')
                    ->nullable();

                $table
                    ->string('pipeline_profile')
                    ->index();

                $table->string(
                    'source_language',
                    2,
                );

                $table->string(
                    'target_language',
                    2,
                );

                $table
                    ->decimal(
                        'input_audio_duration_ms',
                        12,
                        2,
                    )
                    ->nullable();

                $table
                    ->text('stt_transcript')
                    ->nullable();

                $table
                    ->text('translated_text')
                    ->nullable();

                $table
                    ->boolean('success')
                    ->default(false);

                $table
                    ->string('failed_stage')
                    ->nullable();

                $table
                    ->text('error_message')
                    ->nullable();

                /*
                 * Provider/server/browser latency metrics.
                 *
                 * Examples:
                 * - server_stop_to_stt_ms
                 * - translation_ms
                 * - server_stop_to_first_tts_audio_ms
                 * - browser_stop_to_first_audible_playback_ms
                 * - browser_input_end_to_first_audible_audio_received_ms
                 */
                $table
                    ->json('metrics')
                    ->nullable();

                $table
                    ->string('quality_rating')
                    ->nullable()
                    ->index();

                $table
                    ->json('quality_issues')
                    ->nullable();

                $table
                    ->timestamp('reviewed_at')
                    ->nullable();

                $table->timestamps();

                $table->index(
                    [
                        'source_language',
                        'target_language',
                    ],
                    'live_pipeline_runs_direction_index',
                );
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'live_pipeline_runs',
        );
    }
};
