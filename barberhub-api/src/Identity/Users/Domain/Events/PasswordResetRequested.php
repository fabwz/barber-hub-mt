<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Events;

/**
 * Someone asked to reset the password of an email (RF-41). It is published whether or not an account has that email, so the answer to
 * the request is the same; whoever listens finds out, away from the request, if there is an account to write to. The event carries no secret.
 */
final readonly class PasswordResetRequested
{
    /** Hold the email as it was normalised. */
    public function __construct(public string $email) {}
}
