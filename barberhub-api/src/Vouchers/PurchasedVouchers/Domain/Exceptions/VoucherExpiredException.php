<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class VoucherExpiredException extends BusinessRuleException
{
    /** Build the exception for a voucher used after its validity ended (BO-06, BO-07). */
    public static function create(): self
    {
        return new self('vouchers.expired');
    }

    /** Stable type of the problem. */
    public function rule(): string
    {
        return 'bono-vencido';
    }
}
