<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class VoucherDefinitionHasSalesException extends BusinessRuleException
{
    /** Build the exception for a change to the sessions, the validity or the services of a voucher that was already sold (RF-26). */
    public static function create(): self
    {
        return new self('vouchers.definition_has_sales');
    }

    /** Stable type of the problem the API answers. */
    public function rule(): string
    {
        return 'bono-con-ventas';
    }
}
