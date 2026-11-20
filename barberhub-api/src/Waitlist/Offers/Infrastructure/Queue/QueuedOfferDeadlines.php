<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Queue;

use DateTimeImmutable;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Waitlist\Offers\Application\Contracts\OfferDeadlines;
use Src\Waitlist\Offers\Infrastructure\Jobs\ExpireOfferJob;

final readonly class QueuedOfferDeadlines implements OfferDeadlines
{
    /** Receive the port that tells which barbershop the operation belongs to, to leave it in the task. */
    public function __construct(private BarbershopContext $context) {}

    /**
     * Leave a job in the queue that only becomes available when the offer runs out. The queue counts whole seconds and the deadline may fall in
     * the middle of one, so the job waits one second more: it never runs before the offer is overdue, and the line still moves on well within
     * the minute that LE-05 allows.
     */
    public function expireAt(string $offerId, DateTimeImmutable $at): void
    {
        ExpireOfferJob::dispatch($offerId, $this->context->current()->value)->delay($at->modify('+1 second'));
    }
}
