<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class VoucherNotActiveException extends BusinessRuleException
{
    /** Build the exception for a voucher whose payment is not confirmed yet: it has no sessions to use (BO-02). */
    public static function create(): self
    {
        return new self('vouchers.not_active');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
