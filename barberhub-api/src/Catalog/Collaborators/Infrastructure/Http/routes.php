<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Catalog\Collaborators\Infrastructure\Http\Controllers\CollaboratorController;

// Administration: only the owner of the barbershop. A collaborator reads their own agenda through another route (#58).
Route::prefix('barberias/{barberia}/administracion/colaboradores')
    ->middleware(['auth:sanctum', 'barbershop', 'role:owner'])
    ->name('catalog.collaborators.')
    ->controller(CollaboratorController::class)
    ->whereUuid(['barberia', 'colaborador'])
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('mi-perfil', 'storeOwn')->name('store-own');
        Route::get('{colaborador}', 'show')->name('show');
        Route::patch('{colaborador}', 'update')->name('update');
        Route::put('{colaborador}/servicios', 'updateServices')->name('update-services');
        Route::put('{colaborador}/horario', 'updateSchedule')->name('update-schedule');
    });
