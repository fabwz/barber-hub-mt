<?php

declare(strict_types=1);

return [

    /*
    | Provider used for a new charge when the barbershop has not chosen a gateway (PA-02): "onvo" or "paypal".
    */
    'default_gateway' => env('PAYMENTS_DEFAULT_GATEWAY', 'onvo'),

    /*
    | Adapter of each provider, by the value of PaymentProvider. The adapters arrive with their own issues (ONVO Pay, PayPal)
    | and register themselves here; a provider without an adapter fails with PaymentGatewayNotConfiguredException.
    */
    'gateways' => [],

];
