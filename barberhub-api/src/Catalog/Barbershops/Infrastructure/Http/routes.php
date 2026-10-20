<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Catalog\Barbershops\Infrastructure\Http\Controllers\BarbershopController;

Route::post('barberias', [BarbershopController::class, 'store'])
    ->middleware('auth:sanctum')
    ->name('catalog.barbershops.store');
