<?php

declare(strict_types=1);

return [
    /*
    | Resend, the mail service of the deployment (RF-33, RF-34). The key lives only in the environment of the server (RESEND_API_KEY) and is
    | never written in the repository. In development and in the tests MAIL_MAILER is `log` or `array` and nothing is sent.
    */
    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],
];
