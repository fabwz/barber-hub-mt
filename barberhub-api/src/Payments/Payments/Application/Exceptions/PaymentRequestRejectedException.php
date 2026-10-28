<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Exceptions;

use Src\Shared\Domain\Exceptions\InvalidValueException;

/** What the customer or the owner sees when the provider does not accept the data of the payment: the existing type `datos-invalidos` (422). */
final class PaymentRequestRejectedException extends InvalidValueException
{
    /** Build the exception, with a message that says nothing of the provider. */
    public static function create(): self
    {
        return new self('payments.request_rejected');
    }
}
