<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Catalog\Barbershops\Infrastructure\Http\Controllers\BarbershopController;
use Src\Catalog\Barbershops\Infrastructure\Http\Controllers\PaymentPolicyController;
use Src\Catalog\Barbershops\Infrastructure\Http\Controllers\PublicBarbershopController;

Route::post('barberias', [BarbershopController::class, 'store'])
    ->middleware('auth:sanctum')
    ->name('catalog.barbershops.store');

// Administration: only the owner of the barbershop. There is no public route.
Route::prefix('barberias/{barberia}/administracion/cobro')
    ->middleware(['auth:sanctum', 'barbershop', 'role:owner'])
    ->name('catalog.barbershops.payment-policy.')
    ->controller(PaymentPolicyController::class)
    ->whereUuid('barberia')
    ->group(function (): void {
        Route::get('/', 'show')->name('show');
        Route::patch('/', 'update')->name('update');
    });

// Public: the link is how a customer reaches a barbershop before having any session (RD-18).
Route::get('enlaces/{enlace}', [PublicBarbershopController::class, 'show'])
    ->name('catalog.barbershops.show-by-link');
