<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Vouchers\VoucherDefinitions\Infrastructure\Http\Controllers\VoucherDefinitionController;

// Administration: only the owner of the barbershop. The "role" middleware goes after "barbershop", which sets the role it reads.
Route::prefix('barberias/{barberia}/administracion/bonos')
    ->middleware(['auth:sanctum', 'barbershop', 'role:owner'])
    ->name('vouchers.definitions.')
    ->controller(VoucherDefinitionController::class)
    ->whereUuid(['barberia', 'bono'])
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('{bono}', 'show')->name('show');
        Route::patch('{bono}', 'update')->name('update');
    });
