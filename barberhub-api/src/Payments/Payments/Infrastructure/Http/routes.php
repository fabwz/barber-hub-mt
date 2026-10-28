<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Payments\Payments\Infrastructure\Http\Controllers\PaymentController;

// Where a provider sends the customer back after the payment page. It is public, belongs to no barbershop and changes nothing.
Route::get('pagos/retorno', [PaymentController::class, 'returned'])->name('payments.payments.returned');

// The customer pays their own reservation. The plain mode of the barbershop gives a person without a role the usual 404 (UP-06)
// and the role filter refuses the owner and a collaborator with 403; the use case finds the reservation among the customer's own.
Route::post('barberias/{barberia}/reservas/{reserva}/pagos', [PaymentController::class, 'store'])
    ->middleware(['auth:sanctum', 'barbershop', 'role:customer'])
    ->name('payments.payments.store')
    ->whereUuid(['barberia', 'reserva']);

// The customer reads the payments of their own reservations and the owner any of the barbershop; a collaborator is refused.
Route::get('barberias/{barberia}/pagos/{pago}', [PaymentController::class, 'show'])
    ->middleware(['auth:sanctum', 'barbershop', 'role:owner,customer'])
    ->name('payments.payments.show')
    ->whereUuid(['barberia', 'pago']);

// Only the owner gives money back (PA-08).
Route::post('barberias/{barberia}/pagos/{pago}/reembolsos', [PaymentController::class, 'refund'])
    ->middleware(['auth:sanctum', 'barbershop', 'role:owner'])
    ->name('payments.payments.refund')
    ->whereUuid(['barberia', 'pago']);
