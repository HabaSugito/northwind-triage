<?php

// Routes in this file are automatically prefixed with /api by Laravel's bootstrap.
use App\Http\Controllers\TriageController;
use Illuminate\Support\Facades\Route;

Route::post('/triage', [TriageController::class, 'triage']);
Route::get('/health', [TriageController::class, 'health']);
