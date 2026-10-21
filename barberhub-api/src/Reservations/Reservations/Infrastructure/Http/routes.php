<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Reservations\Reservations\Infrastructure\Http\Controllers\ReservationController;

// A customer books: the first reservation creates their role in the barbershop, so a person without one goes through (RD-18).
Route::post('barberias/{barberia}/reservas', [ReservationController::class, 'store'])
    ->middleware(['auth:sanctum', 'barbershop:customer'])
    ->name('reservations.reservations.store')
    ->whereUuid('barberia');

// A customer reads their own reservations: a person without a role gets an empty list, not a 404 (RD-18, CL-04). The
// owner or a collaborator of the barbershop is refused with 403 by the same mode.
Route::get('barberias/{barberia}/mis-reservas', [ReservationController::class, 'mine'])
    ->middleware(['auth:sanctum', 'barbershop:customer'])
    ->name('reservations.reservations.mine')
    ->whereUuid('barberia');

// The owner reads those of every collaborator and a collaborator, those of their own agenda (CO-04, UP-06).
Route::get('barberias/{barberia}/reservas', [ReservationController::class, 'index'])
    ->middleware(['auth:sanctum', 'barbershop', 'role:owner,collaborator'])
    ->name('reservations.reservations.index')
    ->whereUuid('barberia');

// One detail for the three roles, each with its own projection (RF-14, UP-02). The plain mode of the barbershop gives a
// person without a role the usual 404 (UP-06); the controller picks the projection by the role the middleware found.
Route::get('barberias/{barberia}/reservas/{reserva}', [ReservationController::class, 'show'])
    ->middleware(['auth:sanctum', 'barbershop'])
    ->name('reservations.reservations.show')
    ->whereUuid(['barberia', 'reserva']);

// The customer cancels their own and the owner any of the barbershop (RF-16). The plain mode gives a person without a
// role the usual 404 (UP-06) and the role filter refuses a collaborator with 403; the controller picks the author by role.
Route::post('barberias/{barberia}/reservas/{reserva}/cancelacion', [ReservationController::class, 'cancel'])
    ->middleware(['auth:sanctum', 'barbershop', 'role:owner,customer'])
    ->name('reservations.reservations.cancel')
    ->whereUuid(['barberia', 'reserva']);
