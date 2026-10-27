<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

/** What the customer or the owner sees when the provider cannot be reached: the existing type `servicio-no-disponible` (503). */
final class PaymentServiceUnavailableException extends BusinessRuleException
{
    /** Build the exception for a provider that did not answer in time or answered with an error. */
    public static function create(): self
    {
        return new self('payments.gateway_unavailable');
    }

    public function rule(): string
    {
        return 'servicio-no-disponible';
    }
}
