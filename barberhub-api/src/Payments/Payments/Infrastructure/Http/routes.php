<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Payments\Payments\Infrastructure\Http\Controllers\PaymentController;
use Src\Payments\Payments\Infrastructure\Http\Controllers\PaymentNoticeController;
use Src\Payments\Payments\Infrastructure\Http\Controllers\VoucherPurchaseController;
use Src\Payments\Payments\Infrastructure\Http\Middleware\GuardNoticeBody;

// Where a provider sends the customer back after the payment page. It is public, belongs to no barbershop and changes nothing.
Route::get('pagos/retorno', [PaymentController::class, 'returned'])->name('payments.payments.returned');

// What the gateways call to tell the result of a payment (RF-22). Public, with no session and no barbershop in the route: the
// origin is verified by each provider's way, the payment is found from the reference of the provider and the barbershop is its own.
Route::post('pagos/avisos/onvo', [PaymentNoticeController::class, 'onvo'])
    ->middleware(['throttle:payment-notices', GuardNoticeBody::class])
    ->name('payments.notices.onvo');
Route::post('pagos/avisos/paypal', [PaymentNoticeController::class, 'paypal'])
    ->middleware(['throttle:payment-notices', GuardNoticeBody::class])
    ->name('payments.notices.paypal');

// The customer pays their own reservation. The plain mode of the barbershop gives a person without a role the usual 404 (UP-06)
// and the role filter refuses the owner and a collaborator with 403; the use case finds the reservation among the customer's own.
Route::post('barberias/{barberia}/reservas/{reserva}/pagos', [PaymentController::class, 'store'])
    ->middleware(['auth:sanctum', 'barbershop', 'role:customer'])
    ->name('payments.payments.store')
    ->whereUuid(['barberia', 'reserva']);

// A customer buys a voucher (RF-27): the first purchase creates their role in the barbershop, so a person without one goes through
// (RD-18, UP-09) and the owner or a collaborator of the barbershop gets 403 `prohibido` (UP-07). The voucher is the one on sale of the URL.
Route::post('barberias/{barberia}/bonos/{bono}/compras', [VoucherPurchaseController::class, 'store'])
    ->middleware(['auth:sanctum', 'barbershop:customer'])
    ->name('payments.vouchers.purchase')
    ->whereUuid(['barberia', 'bono']);

// The customer reads the payments of their own reservations or vouchers and the owner any of the barbershop; a collaborator is refused.
Route::get('barberias/{barberia}/pagos/{pago}', [PaymentController::class, 'show'])
    ->middleware(['auth:sanctum', 'barbershop', 'role:owner,customer'])
    ->name('payments.payments.show')
    ->whereUuid(['barberia', 'pago']);

// Only the owner gives money back (PA-08).
Route::post('barberias/{barberia}/pagos/{pago}/reembolsos', [PaymentController::class, 'refund'])
    ->middleware(['auth:sanctum', 'barbershop', 'role:owner'])
    ->name('payments.payments.refund')
    ->whereUuid(['barberia', 'pago']);
