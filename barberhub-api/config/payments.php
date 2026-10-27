<?php

declare(strict_types=1);

use Src\Payments\Payments\Infrastructure\Gateways\OnvoGateway;

return [

    /*
    | Provider used for a new charge when the barbershop has not chosen a gateway (PA-02): "onvo" or "paypal".
    */
    'default_gateway' => env('PAYMENTS_DEFAULT_GATEWAY', 'onvo'),

    /*
    | Adapter of each provider, by the value of PaymentProvider. The adapters arrive with their own issues (ONVO Pay, PayPal)
    | and register themselves here; a provider without an adapter fails with PaymentGatewayNotConfiguredException.
    */
    'gateways' => [
        'onvo' => OnvoGateway::class,
    ],

    /*
    | ONVO Pay (RF-20). Only a test key (onvo_test_) is accepted: the adapter refuses a live one (PA-09). The return URL is where
    | the customer comes back after a 3DS check; the SINPE Móvil destination is the number ONVO Pay gives for the transfers.
    */
    'onvo' => [
        'secret_key' => env('ONVO_SECRET_KEY', ''),
        'base_url' => env('ONVO_BASE_URL') ?: 'https://api.onvopay.com/v1',
        'return_url' => env('ONVO_RETURN_URL', ''),
        'sinpe_destination' => env('ONVO_SINPE_DESTINATION') ?: '+506 70196686',
    ],

];
