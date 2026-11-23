<?php

declare(strict_types=1);

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    | Disk of the files that barbershops upload (logo, banner, service images; RF-46, MT-05). Its name is read by
    | FilesystemBarbershopFileStorage. The files are not served from a public folder: GET /api/v1/archivos/... streams them,
    | so their address is relative and works behind the proxy of the web client as well as in production.
    */
    'media' => env('FILESYSTEM_MEDIA', 'public'),

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => '/api/v1/archivos',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],
    ],

];
