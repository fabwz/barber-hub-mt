<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\DTOs;

/** A service a voucher covers, as the customer reads it: its identifier and its name (BO-06). */
final readonly class CoveredService
{
    public function __construct(public string $id, public string $name) {}
}
