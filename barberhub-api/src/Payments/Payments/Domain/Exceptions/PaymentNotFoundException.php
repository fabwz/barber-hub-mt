<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class PaymentNotFoundException extends NotFoundException
{
    /** Build the exception for a payment that does not exist or belongs to another barbershop; both read the same (MT-03). */
    public static function create(): self
    {
        return new self('problems.no-encontrado.detail');
    }
}
