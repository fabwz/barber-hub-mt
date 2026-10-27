<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

final class PaymentTransitionNotAllowedException extends DomainException
{
    /** Build the exception for a jump the statuses of a payment do not have, or a change after a final status. */
    public static function create(): self
    {
        return new self('payments.transition_not_allowed');
    }
}
