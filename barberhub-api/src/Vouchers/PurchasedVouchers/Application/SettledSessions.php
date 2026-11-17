<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application;

use Src\Vouchers\PurchasedVouchers\Domain\Entities\VoucherMovement;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\VoucherMovementType;

/** Reads the record of a voucher to tell whether the session of a reservation was already given back or used (CA-06). */
final class SettledSessions
{
    /**
     * Tell whether the record has the release or the consumption of the reservation.
     *
     * @param  list<VoucherMovement>  $record
     */
    public static function in(array $record, string $reservationId): bool
    {
        return array_any(
            $record,
            fn (VoucherMovement $movement): bool => $movement->reservationId?->value === $reservationId
                && ($movement->type === VoucherMovementType::Release || $movement->type === VoucherMovementType::Consume),
        );
    }
}
