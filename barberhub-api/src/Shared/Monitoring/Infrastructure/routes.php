<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Shared\Monitoring\Infrastructure\Http\Controllers\MonitoringPanelController;
use Src\Shared\Monitoring\Infrastructure\Http\Middleware\RequireMonitoringCredentials;

// A route of the platform, outside /api/v1 (so it is not in the OpenAPI contract) and in no middleware group: the credentials of the panel are its only door.
Route::get('/monitoreo', MonitoringPanelController::class)
    ->middleware(RequireMonitoringCredentials::class)
    ->name('monitoreo');
