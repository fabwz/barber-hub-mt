<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Catalog\Barbershops\Infrastructure\Http\Controllers\BarbershopController;
use Src\Catalog\Barbershops\Infrastructure\Http\Controllers\PublicBarbershopController;

Route::post('barberias', [BarbershopController::class, 'store'])
    ->middleware('auth:sanctum')
    ->name('catalog.barbershops.store');

// Public: the link is how a customer reaches a barbershop before having any session (RD-18).
Route::get('enlaces/{enlace}', [PublicBarbershopController::class, 'show'])
    ->name('catalog.barbershops.show-by-link');
