<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Domain\Entities;

use DateTimeImmutable;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\ReservationId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\VoucherMovementType;
use Src\Vouchers\Shared\Domain\ValueObjects\SessionCount;

/**
 * An entry of the record of a balance (BO-09). The record only grows: an entry is never edited or deleted, so the sum of the
 * entries of a voucher is its balance. It has no domain identity; the row it is stored in does.
 */
final readonly class VoucherMovement
{
    private function __construct(
        public VoucherMovementType $type,
        public int $sessionsDelta,
        public ?ReservationId $reservationId,
        public DateTimeImmutable $at,
    ) {}

    /** The sessions of the voucher, once the payment is confirmed. */
    public static function purchase(SessionCount $sessions, DateTimeImmutable $at): self
    {
        return new self(VoucherMovementType::Purchase, VoucherMovementType::Purchase->delta($sessions->value), null, $at);
    }

    /** A session set aside for a reservation. */
    public static function hold(ReservationId $reservation, DateTimeImmutable $at): self
    {
        return new self(VoucherMovementType::Hold, VoucherMovementType::Hold->delta(), $reservation, $at);
    }

    /** The session of a reservation the customer used or lost: the balance does not change. */
    public static function consume(ReservationId $reservation, DateTimeImmutable $at): self
    {
        return new self(VoucherMovementType::Consume, VoucherMovementType::Consume->delta(), $reservation, $at);
    }

    /** A session given back for a reservation. */
    public static function release(ReservationId $reservation, DateTimeImmutable $at): self
    {
        return new self(VoucherMovementType::Release, VoucherMovementType::Release->delta(), $reservation, $at);
    }

    /** Rebuild an entry from its stored state; the kind, the amount and the reservation must agree, as the table also demands. */
    public static function reconstitute(VoucherMovementType $type, int $sessionsDelta, ?ReservationId $reservationId, DateTimeImmutable $at): self
    {
        $validAmount = $type === VoucherMovementType::Purchase ? $sessionsDelta > 0 : $sessionsDelta === $type->delta();

        if (! $validAmount || $type->hasReservation() !== ($reservationId !== null)) {
            throw new InvalidValueException('vouchers.movement_invalid');
        }

        return new self($type, $sessionsDelta, $reservationId, $at);
    }

    /**
     * What the record says the balance is.
     *
     * @param  list<self>  $movements
     */
    public static function balanceOf(array $movements): int
    {
        return array_sum(array_map(fn (self $movement): int => $movement->sessionsDelta, $movements));
    }
}
