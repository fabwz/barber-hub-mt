<?php

declare(strict_types=1);

namespace Src\Vouchers\Shared\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class VoucherSalesDisabledException extends BusinessRuleException
{
    /** Build the exception for a barbershop that has the vouchers switched off: no new voucher is bought or defined (RF-09, BO-01). */
    public static function create(): self
    {
        return new self('vouchers.sales_disabled');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
