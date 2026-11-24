<?php

declare(strict_types=1);

namespace Tests\Support\Notifications;

use Resend\Contracts\Client;

/** A Resend client that reaches no server. */
final class FakeResendClient implements Client
{
    public function __construct(public RecordingResendEmails $emails = new RecordingResendEmails) {}
}
