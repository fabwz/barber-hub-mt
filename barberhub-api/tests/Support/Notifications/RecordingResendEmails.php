<?php

declare(strict_types=1);

namespace Tests\Support\Notifications;

use Exception;

/** The `emails` service of the Resend client: keeps the calls it gets and fails when it is told to, as the provider does with a 429. */
final class RecordingResendEmails
{
    /** @var list<array{array<string, mixed>, array<string, mixed>}> */
    public array $calls = [];

    public function __construct(private readonly ?Exception $failure = null) {}

    /**
     * Keep the call; answer an email with an identifier, or fail.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $options
     */
    public function send(array $parameters, array $options = []): object
    {
        $this->calls[] = [$parameters, $options];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return (object) ['id' => 'email-1'];
    }
}
