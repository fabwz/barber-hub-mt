<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Identity\Users\Infrastructure\Http\Controllers\AccountController;
use Src\Identity\Users\Infrastructure\Http\Controllers\TokenController;

Route::post('cuentas', [AccountController::class, 'store'])->name('identity.users.store');

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
