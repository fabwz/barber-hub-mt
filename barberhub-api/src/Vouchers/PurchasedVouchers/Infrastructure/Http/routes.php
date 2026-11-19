<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Http\Controllers\PurchasedVoucherController;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Http\Controllers\VoucherOnSaleController;

// A customer reads the vouchers the barbershop sells and what each covers (RF-27, UP-09): like the purchase of the same URL, a person
// without a role goes through and nothing is created, and the owner or a collaborator of the barbershop is refused with 403.
Route::get('barberias/{barberia}/bonos', [VoucherOnSaleController::class, 'index'])
    ->middleware(['auth:sanctum', 'barbershop:customer'])
    ->name('vouchers.on-sale.index')
    ->whereUuid('barberia');

// A customer reads their own vouchers: a person without a role gets an empty list, not a 404 (RD-18, CL-04). The owner or a
// collaborator of the barbershop is refused with 403 by the same mode.
Route::get('barberias/{barberia}/mis-bonos', [PurchasedVoucherController::class, 'mine'])
    ->middleware(['auth:sanctum', 'barbershop:customer'])
    ->name('vouchers.purchased.mine')
    ->whereUuid('barberia');

// The owner reads the vouchers sold in the barbershop (RF-29, MT-02); a collaborator and a customer are refused.
Route::get('barberias/{barberia}/administracion/bonos-vendidos', [PurchasedVoucherController::class, 'sold'])
    ->middleware(['auth:sanctum', 'barbershop', 'role:owner'])
    ->name('vouchers.purchased.sold')
    ->whereUuid('barberia');
