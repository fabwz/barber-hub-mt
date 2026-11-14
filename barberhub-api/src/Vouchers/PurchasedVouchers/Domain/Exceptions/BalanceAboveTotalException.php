<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class BalanceAboveTotalException extends BusinessRuleException
{
    /** Build the exception for a session given back to a voucher that already has all its sessions. */
    public static function create(): self
    {
        return new self('vouchers.balance_above_total');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
