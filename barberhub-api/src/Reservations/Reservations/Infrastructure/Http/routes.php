<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Reservations\Reservations\Infrastructure\Http\Controllers\ReservationController;

// A customer books: the first reservation creates their role in the barbershop, so a person without one goes through (RD-18).
Route::post('barberias/{barberia}/reservas', [ReservationController::class, 'store'])
    ->middleware(['auth:sanctum', 'barbershop:customer'])
    ->name('reservations.reservations.store')
    ->whereUuid('barberia');
