<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Monitoring panel (RNF-15, ADR 0013)
|--------------------------------------------------------------------------
|
| One switch turns on both the recording of the requests and the panel at /monitoreo. It is off by default and in the tests. The panel
| also stays closed (404) until a user and the bcrypt hash of its password are configured. Never write a real value in the repository.
*/

return [

    'enabled' => filter_var(env('MONITOR_ENABLED', false), FILTER_VALIDATE_BOOL),

    'user' => env('MONITOR_USER'),

    // Bcrypt hash (php -r "echo password_hash('the-password', PASSWORD_BCRYPT);"), never the password itself.
    'password_hash' => env('MONITOR_PASSWORD_HASH'),

    // Failed sign-ins of one address before the panel refuses even the right password, and for how long (seconds).
    'max_attempts' => 5,
    'lockout_seconds' => 300,

    // Without a check of the health route for this long the panel says so: nobody is asking, or the server is down.
    'stale_after_minutes' => 10,

    // A task of the `default` queue waiting longer than this many seconds is marked as late (NO-01 expects the notices within 60 s).
    'queue_alert_seconds' => 60,

];
