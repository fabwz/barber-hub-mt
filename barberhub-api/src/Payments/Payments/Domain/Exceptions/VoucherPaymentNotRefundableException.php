<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class VoucherPaymentNotRefundableException extends BusinessRuleException
{
    /** Build the exception for the payment of a voucher: a voucher is neither refundable nor transferable (BO-07). */
    public static function create(): self
    {
        return new self('payments.voucher_not_refundable');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
