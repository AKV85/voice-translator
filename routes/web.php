<?php

use App\Http\Controllers\BenchmarkRecorderController;
use App\Http\Controllers\TranscriptionController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'translator')
    ->name('translator');

Route::post('/transcribe', TranscriptionController::class)
    ->name('transcribe');

Route::prefix('benchmark')
    ->name('benchmark.')
    ->group(function (): void {
        Route::get('/recorder', [BenchmarkRecorderController::class, 'index'])
            ->name('recorder');

        Route::post('/recorder/audio', [BenchmarkRecorderController::class, 'store'])
            ->name('recorder.audio.store');
    });
