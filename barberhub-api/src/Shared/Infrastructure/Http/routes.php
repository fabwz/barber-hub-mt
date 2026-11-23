<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Shared\Infrastructure\Http\Controllers\ApiIndexController;
use Src\Shared\Infrastructure\Http\Controllers\BarbershopFileController;

Route::get('/', ApiIndexController::class)->name('api.index');

// Public: the logo, the banner and the images of the services of a barbershop (MT-05, MT-08, SV-07). The folder is derived from {barberia}, so a file of another barbershop is never reached.
Route::get('archivos/barbershops/{barberia}/{carpeta}/{archivo}', [BarbershopFileController::class, 'show'])
    ->middleware('barbershop:public')
    ->name('shared.files.show')
    ->whereUuid('barberia')
    ->whereIn('carpeta', ['logo', 'banner', 'servicios'])
    ->where('archivo', '[A-Za-z0-9_-]+\.(jpg|png|webp)');
