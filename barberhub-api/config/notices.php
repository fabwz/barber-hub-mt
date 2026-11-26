<?php

declare(strict_types=1);

return [
    /*
    | Emails the workers may send per second. Resend limits the requests per second of an account (its free plan also has a daily and a
    | monthly cap), so the tasks that send a notice go through a rate limiter; the ones over it wait in the queue instead of failing.
    | Check the current limits of the plan in the Resend documentation before raising it.
    */
    'mail_per_second' => (int) env('NOTICES_MAIL_PER_SECOND', 2),

    /*
    | Address of the web client, which the links of the emails point to (the link to reset a password, RF-41). No slash at the end.
    */
    'web_url' => env('WEB_URL', 'http://localhost:5173'),
];
