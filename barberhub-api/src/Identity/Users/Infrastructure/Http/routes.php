<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Identity\Users\Infrastructure\Http\Controllers\AccountController;
use Src\Identity\Users\Infrastructure\Http\Controllers\PasswordController;
use Src\Identity\Users\Infrastructure\Http\Controllers\ProfileController;
use Src\Identity\Users\Infrastructure\Http\Controllers\TokenController;

Route::post('cuentas', [AccountController::class, 'store'])->name('identity.users.store');

Route::middleware('auth:sanctum')->controller(ProfileController::class)->group(function (): void {
    Route::get('cuenta', 'show')->name('identity.profile.show');
    Route::put('cuenta', 'update')->name('identity.profile.update');
});

Route::put('cuenta/contrasena', [PasswordController::class, 'update'])
    ->middleware('auth:sanctum')
    ->name('identity.password.update');

Route::prefix('tokens')
    ->name('identity.tokens.')
    ->controller(TokenController::class)
    ->group(function (): void {
        Route::post('/', 'store')->name('store');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::delete('actual', 'destroyCurrent')->name('destroy-current');
            Route::delete('/', 'destroyAll')->name('destroy-all');
        });
    });
