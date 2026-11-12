<?php

declare(strict_types=1);

use Src\Reservations\Reservations\Infrastructure\Ai\GeminiReservationExtractor;

return [

    /*
    | Provider behind the reservation by phrase (RF-37): a key of `providers`. The port ReservationExtractor is bound to its adapter,
    | so changing provider is writing another adapter, listing it here and changing this value.
    */
    'provider' => 'gemini',

    'providers' => [
        'gemini' => GeminiReservationExtractor::class,
    ],

    /*
    | Google Gemini, generateContent. The model has no default on purpose: its name is not written in the code, and without it the
    | adapter fails with a configuration error. Without a key the extractor answers "unavailable" and never calls Google. The free
    | tier of Google may use what is sent to improve its products: only demonstration data should go through it.
    */
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY', ''),
        'model' => env('GEMINI_MODEL', ''),
        'base_url' => env('GEMINI_BASE_URL') ?: 'https://generativelanguage.googleapis.com/v1beta',
    ],

    /*
    | Circuit breaker around the provider (IA-05, RNF-11). `failures` consecutive failures of availability (timeout, connection, 429, 5xx)
    | inside `window_seconds` open it for `open_seconds`, during which the provider is not called; then one probe call decides. A 401 or
    | a 403 opens it at once. `store` is the cache store with the shared state: not the default one (the database), because a request
    | runs in a transaction that is rolled back when it fails and the failures would be erased. Empty values use the defaults.
    */
    'circuit' => [
        'failures' => (int) (env('AI_CIRCUIT_FAILURES') ?: 3),
        'window_seconds' => (int) (env('AI_CIRCUIT_WINDOW_SECONDS') ?: 60),
        'open_seconds' => (int) (env('AI_CIRCUIT_OPEN_SECONDS') ?: 60),
        'store' => env('AI_CIRCUIT_CACHE_STORE') ?: 'file',
    ],

];
