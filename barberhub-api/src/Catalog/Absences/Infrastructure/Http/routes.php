<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Catalog\Absences\Infrastructure\Http\Controllers\AbsenceController;

// Administration: only the owner of the barbershop blocks the agenda of a collaborator (RF-07). There is no public route.
Route::prefix('barberias/{barberia}/administracion/ausencias')
    ->middleware(['auth:sanctum', 'barbershop', 'role:owner'])
    ->name('catalog.absences.')
    ->controller(AbsenceController::class)
    ->whereUuid(['barberia', 'ausencia'])
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::delete('{ausencia}', 'destroy')->name('destroy');
    });
