<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\HeartbeatController;
use Illuminate\Support\Facades\Route;

// Mounted at /api by bootstrap/app.php (php artisan install:api), so these resolve to /api/v1/...
Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('enrollments', [EnrollmentController::class, 'index']);
        Route::post('enrollments/join', [EnrollmentController::class, 'join'])->middleware('throttle:20,1');
        Route::post('heartbeats', [HeartbeatController::class, 'store'])->middleware('throttle:120,1');
    });
});
