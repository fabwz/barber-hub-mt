<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Domain\Exceptions;

use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Shared\Domain\Exceptions\HasInvalidField;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final class PaymentMethodNotSupportedException extends InvalidValueException implements HasInvalidField
{
    /** Build the exception for a method the provider of the barbershop does not admit (PA-02). */
    public static function create(PaymentMethod $method, PaymentProvider $provider): self
    {
        return new self('payments.method_not_supported', ['method' => $method->value, 'provider' => $provider->value]);
    }

    /** The attribute of the request that carries the method. */
    public function field(): string
    {
        return 'metodo';
    }
}
