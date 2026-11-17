<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\HasInvalidField;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final class VoucherNotRequiredException extends InvalidValueException implements HasInvalidField
{
    /** Build the exception for a request that pays with a voucher in a barbershop that does not charge: there is no payment to replace, so no session is used (RF-28, RE-07). */
    public static function create(): self
    {
        return new self('reservations.voucher_not_required');
    }

    /** The attribute of the request that asks for the voucher. */
    public function field(): string
    {
        return 'con_bono';
    }
}
