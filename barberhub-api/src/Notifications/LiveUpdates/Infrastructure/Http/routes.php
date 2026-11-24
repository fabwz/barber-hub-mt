<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Notifications\LiveUpdates\Infrastructure\Http\Controllers\ChannelAuthorizationController;

// The WebSocket client asks here to subscribe to a private channel (RF-36). The address has no barbershop: the channel name carries it, and the
// rule of the channel (role in that barbershop, own agenda) is decided by the use cases of the module, as the barbershop middleware does for a
// route. The limiter protects the reconnections of a screen that loses its connection in a loop.
Route::post('tiempo-real/autorizacion', ChannelAuthorizationController::class)
    ->middleware(['auth:sanctum', 'throttle:live-authorizations'])
    ->name('live-updates.authorization');
