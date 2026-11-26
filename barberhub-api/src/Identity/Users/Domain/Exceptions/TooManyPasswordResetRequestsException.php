<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\TooManyRequestsException;

final class TooManyPasswordResetRequestsException extends TooManyRequestsException
{
    /** Build the exception for the sixth request of the hour, with the time left to retry (SE-06). */
    public static function create(int $retryAfterSeconds): self
    {
        $seconds = max(1, $retryAfterSeconds);

        return new self('identity.too_many_password_reset_requests', [
            'minutes' => (int) ceil($seconds / 60),
            'seconds' => $seconds,
        ]);
    }

    /** Seconds the person must wait; they travel as a parameter because the base constructor is final. */
    public function retryAfterSeconds(): int
    {
        return (int) $this->parameters()['seconds'];
    }
}
