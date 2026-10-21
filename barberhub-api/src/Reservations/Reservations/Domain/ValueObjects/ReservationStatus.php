<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\ValueObjects;

enum ReservationStatus: string
{
    case Temporary = 'temporary';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case CancelledByCustomer = 'cancelled_by_customer';
    case CancelledByBarbershop = 'cancelled_by_barbershop';
    case Expired = 'expired';

    /**
     * Statuses a reservation in this one can move to (RE-04); the final statuses have none.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Temporary => [self::Confirmed, self::Expired, self::CancelledByCustomer],
            self::Confirmed => [self::Completed, self::NoShow, self::CancelledByCustomer, self::CancelledByBarbershop],
            self::Completed, self::NoShow, self::CancelledByCustomer, self::CancelledByBarbershop, self::Expired => [],
        };
    }

    /** Tell whether a reservation in this status can change to the target one. */
    public function canChangeTo(self $target): bool
    {
        return in_array($target, $this->next(), true);
    }

    /** Tell whether the status can never change again. */
    public function isFinal(): bool
    {
        return $this->next() === [];
    }
}
