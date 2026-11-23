<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Infrastructure\Reservations;

use DateTimeImmutable;
use Src\Catalog\Collaborators\Application\Contracts\UpcomingReservations;
use Src\Catalog\Collaborators\Application\DTOs\UpcomingReservationList;
use Src\Catalog\Collaborators\Domain\ValueObjects\UpcomingReservation;
use Src\Reservations\Reservations\Application\DTOs\ReservationView;
use Src\Reservations\Reservations\Application\UseCases\ListUpcomingConfirmedReservations;

final readonly class ReservationsUpcomingReservations implements UpcomingReservations
{
    /** Receive the Reservations use case that lists the confirmed reservations ahead. */
    public function __construct(private ListUpcomingConfirmedReservations $listUpcoming) {}

    /** Read them through the Reservations module. */
    public function confirmedOf(string $collaboratorId, DateTimeImmutable $now, int $limit): UpcomingReservationList
    {
        $page = $this->listUpcoming->execute($collaboratorId, $now, $limit);

        return new UpcomingReservationList(
            array_map(fn (ReservationView $view): UpcomingReservation => new UpcomingReservation(
                $view->reservation->id->value,
                $view->reservation->interval->start,
                $view->serviceName,
                $view->customerName ?? '',
            ), $page->items),
            $page->total,
        );
    }
}
