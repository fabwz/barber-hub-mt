<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

/** The gateway a payment was charged with does not implement refunds: nothing was asked of the provider and the payment stays as it was. */
final class RefundNotSupportedException extends BusinessRuleException
{
    /** Build the exception for a gateway without refunds. */
    public static function create(): self
    {
        return new self('payments.refund_not_supported');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
