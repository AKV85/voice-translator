<?php

use App\Http\Controllers\TranscriptionController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'translator')
    ->name('translator');

Route::post('/transcribe', TranscriptionController::class)
    ->name('transcribe');
