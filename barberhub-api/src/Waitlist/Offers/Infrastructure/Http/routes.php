<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Waitlist\Offers\Infrastructure\Http\Controllers\OfferController;

// A customer reads the offers they can still answer: a person without a role gets an empty list, not a 404 (RD-18, CL-04). The owner or a
// collaborator of the barbershop is refused with 403 by the same mode.
Route::get('barberias/{barberia}/mis-ofertas', [OfferController::class, 'mine'])
    ->middleware(['auth:sanctum', 'barbershop:customer'])
    ->name('waitlist.offers.mine')
    ->whereUuid('barberia');

// A customer accepts one of their own offers. Like joining the line it is a customer operation of RD-18: a person without a role goes through and
// finds no offer, which is the 404 of any missing resource; the offer of another customer reads the same (CL-04). The owner or a collaborator
// of the barbershop is refused with 403.
Route::post('barberias/{barberia}/ofertas/{oferta}/aceptacion', [OfferController::class, 'accept'])
    ->middleware(['auth:sanctum', 'barbershop:customer'])
    ->name('waitlist.offers.accept')
    ->whereUuid(['barberia', 'oferta']);

// The same for turning one down.
Route::post('barberias/{barberia}/ofertas/{oferta}/rechazo', [OfferController::class, 'reject'])
    ->middleware(['auth:sanctum', 'barbershop:customer'])
    ->name('waitlist.offers.reject')
    ->whereUuid(['barberia', 'oferta']);
