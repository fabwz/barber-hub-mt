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

];
