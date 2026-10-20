<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Catalog\Services\Infrastructure\Http\Controllers\PublicServiceController;
use Src\Catalog\Services\Infrastructure\Http\Controllers\ServiceController;

// Public: a customer sees the active services of a barbershop before having any session (RD-18).
Route::get('barberias/{barberia}/servicios', [PublicServiceController::class, 'index'])
    ->middleware('barbershop:public')
    ->name('catalog.services.public.index')
    ->whereUuid('barberia');

// Administration: only the owner of the barbershop. The "role" middleware goes after "barbershop", which sets the role it reads.
Route::prefix('barberias/{barberia}/administracion/servicios')
    ->middleware(['auth:sanctum', 'barbershop', 'role:owner'])
    ->name('catalog.services.')
    ->controller(ServiceController::class)
    ->whereUuid(['barberia', 'servicio'])
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::patch('{servicio}', 'update')->name('update');
    });
