<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Application\DTOs;

/** A service a voucher covers, as a reader needs it: its identifier and its name (BO-06). */
final readonly class CoveredService
{
    public function __construct(public string $id, public string $name) {}
}
