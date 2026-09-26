<?php

use App\Http\Controllers\Api\V1\AttentionCheckController;
use App\Http\Controllers\Api\V1\ClientHeartbeatController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('api.v1.health');
    Route::post('attention/check', AttentionCheckController::class)
        ->middleware(['throttle:attention-preauth', 'client.auth', 'throttle:attention-client'])
        ->name('api.v1.attention.check');
    Route::post('client/heartbeat', ClientHeartbeatController::class)
        ->middleware(['throttle:attention-preauth', 'client.auth', 'throttle:client-heartbeat'])
        ->name('api.v1.client.heartbeat');
});
