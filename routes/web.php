<?php

use App\Http\Controllers\BenchmarkRecorderController;
use App\Http\Controllers\LivePipelineHistoryController;
use App\Http\Controllers\LivePipelineRunController;
use App\Http\Controllers\PublicDemoSessionController;
use App\Http\Controllers\TranscriptionController;
use App\Http\Middleware\EnsureLivePipelineLabEnabled;
use Illuminate\Support\Facades\Route;

Route::view('/', 'translator')
    ->name('translator');

Route::post(
    '/demo/session',
    PublicDemoSessionController::class,
)
    ->name('demo.session.store');

Route::middleware(
    EnsureLivePipelineLabEnabled::class,
)
    ->group(function (): void {
        Route::view('/live', 'live')
            ->name('live');

        Route::view(
            '/live/history',
            'live-history',
        )
            ->name('live.history');

        Route::get(
            '/live/history/runs',
            LivePipelineHistoryController::class,
        )
            ->name('live.history.runs.index');

        Route::post(
            '/live/runs',
            [LivePipelineRunController::class, 'store'],
        )
            ->name('live.runs.store');

        Route::patch(
            '/live/runs/{livePipelineRun}/quality',
            [LivePipelineRunController::class, 'updateQuality'],
        )
            ->name('live.runs.quality.update');

        Route::post(
            '/transcribe',
            TranscriptionController::class,
        )
            ->name('transcribe');

        Route::prefix('benchmark')
            ->name('benchmark.')
            ->group(function (): void {
                Route::get(
                    '/recorder',
                    [BenchmarkRecorderController::class, 'index'],
                )
                    ->name('recorder');

                Route::post(
                    '/recorder/audio',
                    [BenchmarkRecorderController::class, 'store'],
                )
                    ->name('recorder.audio.store');
            });
    });
