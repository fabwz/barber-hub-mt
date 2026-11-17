<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Payments\Income\Infrastructure\Http\Controllers\IncomeReportController;

// The income of the barbershop over a range of days (RF-25): only the owner. A collaborator and a customer are refused with 403
// (CO-04) and a person without a role in the barbershop gets the usual 404 (UP-06).
Route::get('barberias/{barberia}/administracion/ingresos', [IncomeReportController::class, 'show'])
    ->middleware(['auth:sanctum', 'barbershop', 'role:owner'])
    ->name('payments.income.show')
    ->whereUuid('barberia');
