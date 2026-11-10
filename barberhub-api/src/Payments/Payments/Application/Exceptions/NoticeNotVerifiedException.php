<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

/** A notice whose origin could not be verified (PA-04): 401 `no-autenticado`, with no hint of what failed. */
final class NoticeNotVerifiedException extends BusinessRuleException
{
    public static function create(): self
    {
        return new self('payments.notice_not_verified');
    }

    public function rule(): string
    {
        return 'no-autenticado';
    }
}
