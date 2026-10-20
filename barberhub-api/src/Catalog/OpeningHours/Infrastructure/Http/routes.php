<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Catalog\OpeningHours\Infrastructure\Http\Controllers\TimeSlotController;

// Administration: only the owner of the barbershop. There is no public route: availability (#53) reads the schedule through its use case.
Route::prefix('barberias/{barberia}/administracion/horario')
    ->middleware(['auth:sanctum', 'barbershop', 'role:owner'])
    ->name('catalog.opening-hours.')
    ->controller(TimeSlotController::class)
    ->whereUuid(['barberia', 'franja'])
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::delete('{franja}', 'destroy')->name('destroy');
    });
