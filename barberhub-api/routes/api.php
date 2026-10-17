<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Transitional root route (#16): #33 moves the API under /api/v1.
Route::get('/', fn (): array => ['nombre' => config('app.name')]);
