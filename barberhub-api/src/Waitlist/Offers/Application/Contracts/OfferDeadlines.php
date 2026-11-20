<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\Contracts;

use DateTimeImmutable;

interface OfferDeadlines
{
    /**
     * Leave a task for the moment the 15 minutes of an offer run out (LE-04), so it expires and the line moves on without anybody asking. The
     * task is only a trigger: it finds the offer again and expires it if it is still pending and overdue, so one that was answered meanwhile
     * is left alone. The line moves on at most a minute after the offer expires (LE-05).
     */
    public function expireAt(string $offerId, DateTimeImmutable $at): void;
}
