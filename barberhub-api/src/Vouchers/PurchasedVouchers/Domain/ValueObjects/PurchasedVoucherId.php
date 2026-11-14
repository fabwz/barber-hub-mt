<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** A voucher a customer bought. */
final readonly class PurchasedVoucherId extends Identifier {}
