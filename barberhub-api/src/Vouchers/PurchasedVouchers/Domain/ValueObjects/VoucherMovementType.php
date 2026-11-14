<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Domain\ValueObjects;

/** The kinds of entry of the record of a balance (BO-09). */
enum VoucherMovementType: string
{
    /** The sessions of the voucher once its payment is confirmed. */
    case Purchase = 'purchase';

    /** A session set aside when the reservation is confirmed (BO-04). */
    case Hold = 'hold';

    /** The session of a reservation that ended with the customer using it: it was already taken, so the balance does not change (BO-05). */
    case Consume = 'consume';

    /** The session given back when the customer cancels in time or the barbershop cancels (BO-05). */
    case Release = 'release';

    /** What the entry adds to the balance: the sum of every entry of a voucher is its balance. A purchase adds the sessions bought. */
    public function delta(int $sessions = 1): int
    {
        return match ($this) {
            self::Purchase => $sessions,
            self::Hold => -1,
            self::Release => 1,
            self::Consume => 0,
        };
    }

    /** Tell whether the entry belongs to a reservation. */
    public function hasReservation(): bool
    {
        return $this !== self::Purchase;
    }
}
