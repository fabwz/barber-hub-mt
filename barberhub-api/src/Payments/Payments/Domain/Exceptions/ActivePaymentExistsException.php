<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

final class ActivePaymentExistsException extends DomainException
{
    /** Build the exception for a reservation that already has a payment in course or paid. */
    public static function create(): self
    {
        return new self('payments.active_payment_exists');
    }
}
