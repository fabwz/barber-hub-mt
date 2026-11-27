<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Catalog\Invitations\Infrastructure\Http\Controllers\InvitationController;
use Src\Catalog\Invitations\Infrastructure\Http\Controllers\InvitationLinkController;

// Administration: only the owner of the barbershop invites, sees the pending invitations and cancels them.
Route::prefix('barberias/{barberia}/administracion/invitaciones')
    ->middleware(['auth:sanctum', 'barbershop', 'role:owner'])
    ->name('catalog.invitations.')
    ->controller(InvitationController::class)
    ->whereUuid(['barberia', 'invitacion'])
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::delete('{invitacion}', 'destroy')->name('destroy');
    });

// The invited person has no session and no barbershop in the URL: the link names its barbershop, found with the function of the database.
Route::prefix('invitaciones')
    ->middleware('throttle:invitation-links')
    ->name('catalog.invitation-links.')
    ->controller(InvitationLinkController::class)
    ->group(function (): void {
        Route::post('consulta', 'show')->name('show');
        Route::post('aceptacion', 'accept')->name('accept');
    });
