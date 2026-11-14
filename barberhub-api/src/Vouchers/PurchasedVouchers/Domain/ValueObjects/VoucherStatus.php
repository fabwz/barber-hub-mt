<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Domain\ValueObjects;

/**
 * The stored status of a purchased voucher. Being expired is not a status: the validity is checked at the moment of use and of
 * the query, and no process changes a voucher when it ends (BO-07).
 */
enum VoucherStatus: string
{
    /** Bought and waiting for the confirmation of its payment (BO-02). */
    case Pending = 'pending';

    /** Paid; it has a balance and a validity. */
    case Active = 'active';
}
