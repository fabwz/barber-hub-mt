<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Catalog\Closures\Infrastructure\Http\Controllers\ClosureController;

// Administration: only the owner of the barbershop. There is no public route.
Route::prefix('barberias/{barberia}/administracion/cierres')
    ->middleware(['auth:sanctum', 'barbershop', 'role:owner'])
    ->name('catalog.closures.')
    ->controller(ClosureController::class)
    ->whereUuid(['barberia', 'cierre'])
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::delete('{cierre}', 'destroy')->name('destroy');
    });
