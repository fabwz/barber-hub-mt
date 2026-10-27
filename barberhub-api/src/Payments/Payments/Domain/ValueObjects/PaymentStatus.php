<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Domain\ValueObjects;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Refunded = 'refunded';

    /**
     * Statuses a payment in this one can move to; the final statuses have none.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected, self::Failed],
            self::Approved => [self::Refunded],
            self::Rejected, self::Failed, self::Refunded => [],
        };
    }

    /** Tell whether a payment in this status can change to the target one. */
    public function canChangeTo(self $target): bool
    {
        return in_array($target, $this->next(), true);
    }

    /** Tell whether the status can never change again. */
    public function isFinal(): bool
    {
        return $this->next() === [];
    }

    /** Tell whether a payment in this status still counts for its reservation: one is in course or paid, and only one at a time. */
    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }
}
