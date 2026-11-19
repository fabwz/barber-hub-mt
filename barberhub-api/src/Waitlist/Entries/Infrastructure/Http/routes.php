<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Waitlist\Entries\Infrastructure\Http\Controllers\WaitlistEntryController;

// A customer joins the line: the first row creates their role in the barbershop, so a person without one goes through (RD-18,
// UP-09) and the owner or a collaborator of the barbershop is refused with 403.
Route::post('barberias/{barberia}/filas', [WaitlistEntryController::class, 'store'])
    ->middleware(['auth:sanctum', 'barbershop:customer'])
    ->name('waitlist.entries.store')
    ->whereUuid('barberia');

// A customer reads their own active rows: a person without a role gets an empty list, not a 404 (RD-18, CL-04). The owner or a
// collaborator of the barbershop is refused with 403 by the same mode.
Route::get('barberias/{barberia}/mis-filas', [WaitlistEntryController::class, 'mine'])
    ->middleware(['auth:sanctum', 'barbershop:customer'])
    ->name('waitlist.entries.mine')
    ->whereUuid('barberia');

// A customer leaves one of their own rows. Like joining it is a customer operation of RD-18: a person without a role goes through
// and finds no row, which is the 404 of any missing resource; the row of another customer reads the same (CL-04).
Route::delete('barberias/{barberia}/filas/{fila}', [WaitlistEntryController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'barbershop:customer'])
    ->name('waitlist.entries.destroy')
    ->whereUuid(['barberia', 'fila']);
