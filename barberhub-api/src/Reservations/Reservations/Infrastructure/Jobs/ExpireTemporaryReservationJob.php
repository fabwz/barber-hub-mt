<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Jobs;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Src\Reservations\Reservations\Application\UseCases\ExpireTemporaryReservation;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;

/**
 * Runs when the payment window of a temporary reservation closes and expires it if nothing happened in time (RE-05). It is left in the queue
 * only after the reservation is committed, so a worker never looks for a row that is not there yet.
 */
final class ExpireTemporaryReservationJob implements ShouldQueueAfterCommit
{
    use Queueable;

    /** Attempts before the job is recorded as failed and stays visible in the monitoring (RNF-09). */
    public int $tries = 3;

    /** @var list<int> seconds to wait before the second and third attempts */
    public array $backoff = [5, 15];

    /** Carry the reservation and its barbershop: a worker has no request to take the barbershop from. */
    public function __construct(
        public readonly string $reservationId,
        public readonly string $barbershopId,
    ) {}

    /** Expire the reservation as its barbershop, in its transaction, at the current instant: a worker has no request to take the barbershop from. */
    public function handle(BarbershopProcessScope $scope, ExpireTemporaryReservation $expire, Clock $clock): void
    {
        $scope->run($this->barbershopId, fn (): bool => $expire->execute($this->reservationId, $clock->now()));
    }
}
