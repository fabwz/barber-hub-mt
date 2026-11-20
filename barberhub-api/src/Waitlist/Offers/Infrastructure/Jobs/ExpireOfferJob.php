<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Jobs;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;
use Src\Waitlist\Offers\Application\UseCases\ExpireOffer;

/**
 * Runs when the 15 minutes of an offer run out: it expires the offer and passes the space to the next compatible person (LE-04, LE-05). It is
 * left in the queue only after the offer is committed, so a worker never looks for a row that is not there yet.
 */
final class ExpireOfferJob implements ShouldQueueAfterCommit
{
    use Queueable;

    /** Attempts before the job is recorded as failed and stays visible in the monitoring (RNF-09). */
    public int $tries = 3;

    /** @var list<int> seconds to wait before the second and third attempts, short so the line still moves on in about a minute (LE-05) */
    public array $backoff = [5, 15];

    /** Carry the offer and its barbershop: a worker has no request to take the barbershop from. */
    public function __construct(
        public readonly string $offerId,
        public readonly string $barbershopId,
    ) {}

    /** Expire the offer as its barbershop, in its transaction, at the current instant. */
    public function handle(BarbershopProcessScope $scope, ExpireOffer $expire, Clock $clock): void
    {
        $scope->run($this->barbershopId, fn (): bool => $expire->execute($this->offerId, $clock->now()));
    }
}
