<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Reservations\AssistedClients\Infrastructure\Http\Controllers\AssistedClientController;

// The owner looks for a person they registered before, to book for them (RF-12). The client is created with the reservation, in
// `POST /reservas/asistida`. A collaborator or a customer is refused with 403 and a person without a role gets the usual 404 (UP-06).
Route::get('barberias/{barberia}/clientes-asistidos', [AssistedClientController::class, 'index'])
    ->middleware(['auth:sanctum', 'barbershop', 'role:owner'])
    ->name('reservations.assisted-clients.index')
    ->whereUuid('barberia');
