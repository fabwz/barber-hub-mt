<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\DTOs;

use Src\Shared\Domain\ValueObjects\Money;
use Src\Vouchers\Shared\Domain\ValueObjects\SessionCount;
use Src\Vouchers\Shared\Domain\ValueObjects\ValidityDays;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherDefinitionId;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherName;

/** What a voucher on sale says at the moment of the purchase: the terms that the purchased voucher copies (BO-01). */
final readonly class VoucherOnSale
{
    public function __construct(
        public VoucherDefinitionId $id,
        public VoucherName $name,
        public Money $price,
        public SessionCount $sessionsCount,
        public ValidityDays $validityDays,
    ) {}
}
