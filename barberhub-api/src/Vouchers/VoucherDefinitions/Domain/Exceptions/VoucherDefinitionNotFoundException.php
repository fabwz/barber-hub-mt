<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class VoucherDefinitionNotFoundException extends NotFoundException
{
    /** Build the exception for a voucher definition missing from the barbershop. */
    public static function create(): self
    {
        return new self('vouchers.definition_not_found');
    }
}
