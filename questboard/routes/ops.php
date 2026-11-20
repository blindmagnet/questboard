<?php

use App\Http\Controllers\OpsController;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', [OpsController::class, 'live'])->name('health.live');
Route::get('/health/ready', [OpsController::class, 'ready'])->name('health.ready');
Route::get('/metrics', [OpsController::class, 'metrics'])->name('metrics');
