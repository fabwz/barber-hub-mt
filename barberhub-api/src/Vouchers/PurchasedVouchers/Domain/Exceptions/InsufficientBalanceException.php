<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class InsufficientBalanceException extends BusinessRuleException
{
    /** Build the exception for a voucher with no sessions left, or whose last one went to another reservation (BO-03). */
    public static function create(): self
    {
        return new self('vouchers.insufficient_balance');
    }

    /** Stable type of the problem. */
    public function rule(): string
    {
        return 'saldo-insuficiente';
    }
}
