<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Availability\Slots\Infrastructure\Http\Controllers\AvailabilityController;

// Public: a customer looks for a space before having any session (RD-18).
Route::get('barberias/{barberia}/disponibilidad', [AvailabilityController::class, 'index'])
    ->middleware('barbershop:public')
    ->name('availability.slots.index')
    ->whereUuid('barberia');
