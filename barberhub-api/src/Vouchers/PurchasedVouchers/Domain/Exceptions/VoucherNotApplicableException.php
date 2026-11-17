<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class VoucherNotApplicableException extends BusinessRuleException
{
    /** Build the exception for a customer none of whose vouchers covers the service: a voucher covers only the services it names (BO-06). */
    public static function create(): self
    {
        return new self('vouchers.not_applicable');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
