<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Shared\Infrastructure\Http\Controllers\HealthController;

// Public and outside /api/v1: it is not a business route. Loaded without middleware groups.
Route::get('/salud', HealthController::class)->name('salud');
