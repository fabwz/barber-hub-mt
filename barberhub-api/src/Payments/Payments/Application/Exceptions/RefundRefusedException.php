<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;
use Src\Shared\Domain\Exceptions\HasProblemMembers;

/** The provider refused to give the money back: the payment stays approved and the owner sees why (PA-08). */
final class RefundRefusedException extends BusinessRuleException implements HasProblemMembers
{
    private ?string $reason = null;

    /** Build the exception with the reason the provider gave, if any. */
    public static function because(?string $reason): self
    {
        $exception = new self('payments.refund_refused');
        $exception->reason = $reason;

        return $exception;
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }

    /** The reason of the provider, as `motivo`. */
    public function problemMembers(): array
    {
        return ['motivo' => $this->reason];
    }
}
