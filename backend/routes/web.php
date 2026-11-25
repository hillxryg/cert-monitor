<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\CampaignController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Middleware\EnsureInstructor;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::view('student', 'student.home')->name('student.home');

    Route::middleware(EnsureInstructor::class)->group(function () {
        Route::get('dashboard', [CampaignController::class, 'index'])->name('dashboard');

        // 'create' must be declared before the {campaign} wildcard routes.
        Route::get('campaigns/create', [CampaignController::class, 'create'])->name('campaigns.create');
        Route::post('campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
        Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
        Route::get('campaigns/{campaign}/edit', [CampaignController::class, 'edit'])->name('campaigns.edit');
        Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
        Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');
        Route::patch('campaigns/{campaign}/toggle', [CampaignController::class, 'toggle'])->name('campaigns.toggle');
        Route::get('campaigns/{campaign}/enrollments/{enrollment}', [CampaignController::class, 'audit'])->name('campaigns.audit');
    });
});
