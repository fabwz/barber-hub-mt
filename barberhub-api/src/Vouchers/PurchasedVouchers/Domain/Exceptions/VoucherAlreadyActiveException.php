<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class VoucherAlreadyActiveException extends BusinessRuleException
{
    /** Build the exception for a voucher activated twice: its sessions and its validity are given once (BO-02). */
    public static function create(): self
    {
        return new self('vouchers.already_active');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
