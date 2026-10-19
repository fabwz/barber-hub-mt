<?php

declare(strict_types=1);

use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;

return [

    'api_path' => 'api/v1',

    'export_path' => 'openapi/v1.json',

    'info' => [
        'version' => '1.0.0',
        'description' => 'API de BarberHub: reservas y cobro para barberías.',
    ],

    'ui' => [
        'title' => 'BarberHub API',
    ],

    'middleware' => [],

    'security_strategy' => MiddlewareAuthSecurityStrategy::class,

];
