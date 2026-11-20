<?php

declare(strict_types=1);

namespace Tests\Support\Reservations;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\Contracts\ReservationExpiries;

/** The tasks left for the end of the payment window of a temporary reservation, in the order they were left, for the tests that run the use case without a queue. */
final class RecordingReservationExpiries implements ReservationExpiries
{
    /** @var list<array{string, DateTimeImmutable}> */
    private array $scheduled = [];

    /** Keep the task. */
    public function schedule(string $reservationId, DateTimeImmutable $at): void
    {
        $this->scheduled[] = [$reservationId, $at];
    }

    /**
     * The reservation and the instant of each task, in the order they were left.
     *
     * @return list<array{string, DateTimeImmutable}>
     */
    public function scheduled(): array
    {
        return $this->scheduled;
    }
}
