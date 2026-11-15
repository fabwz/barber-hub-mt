<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\DTOs;

use Src\Vouchers\PurchasedVouchers\Domain\Entities\PurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\VoucherMovement;

/**
 * A paid voucher as it is read at one instant: the voucher, its record, the sessions it shows and whether it has expired. The
 * sessions are the sum of the record, or none once the voucher has expired (BO-07, BO-09); nothing is stored for the expiry.
 */
final readonly class PurchasedVoucherView
{
    /** @param  list<VoucherMovement>  $movements  The record of the voucher, in the order it was written. */
    public function __construct(
        public PurchasedVoucher $voucher,
        public array $movements,
        public int $balance,
        public bool $expired,
        public ?string $customerName,
    ) {}
}
