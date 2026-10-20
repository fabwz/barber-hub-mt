<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\TooManyRequestsException;

final class TooManyLoginAttemptsException extends TooManyRequestsException
{
    /** Build the exception for a locked login, with the time left to retry (SE-05). */
    public static function create(int $retryAfterSeconds): self
    {
        $seconds = max(1, $retryAfterSeconds);

        return new self('identity.too_many_login_attempts', [
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
