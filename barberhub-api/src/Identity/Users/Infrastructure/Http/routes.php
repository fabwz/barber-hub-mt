<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Identity\Users\Infrastructure\Http\Controllers\AccountController;

Route::post('cuentas', [AccountController::class, 'store'])->name('identity.users.store');
