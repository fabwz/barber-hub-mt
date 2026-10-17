<?php

declare(strict_types=1);

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [
        // Serves the requests with the application role (not the table owner).
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'barberhub'),
            'username' => env('DB_USERNAME', 'barberhub_app'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'timezone' => env('APP_TIMEZONE', 'America/Costa_Rica'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],
        // Runs the migrations with the table owner: --database=pgsql_admin.
        'pgsql_admin' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'barberhub'),
            'username' => env('DB_ADMIN_USERNAME', 'barberhub_owner'),
            'password' => env('DB_ADMIN_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'timezone' => env('APP_TIMEZONE', 'America/Costa_Rica'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

];
