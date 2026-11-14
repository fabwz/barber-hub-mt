<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class VoucherNotFoundException extends NotFoundException
{
    /** Build the exception for a voucher the person cannot reach: it does not exist or belongs to another barbershop or customer. It reads like any missing resource (MT-03). */
    public static function create(): self
    {
        return new self('problems.no-encontrado.detail');
    }
}
