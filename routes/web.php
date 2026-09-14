<?php

use App\Http\Controllers\AuthController;
use App\Http\Middleware\EnsureRegistrationEnabled;
use App\Livewire\Counter;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login-ip');
    Route::middleware(EnsureRegistrationEnabled::class)->group(function () {
        Route::view('/register', 'auth.register')->name('register');
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:registration-ip');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/', Counter::class)->name('home');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
