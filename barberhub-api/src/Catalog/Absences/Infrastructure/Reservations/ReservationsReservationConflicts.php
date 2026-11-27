<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Infrastructure\Reservations;

use DateTimeImmutable;
use Src\Catalog\Absences\Application\Contracts\ReservationConflicts;
use Src\Catalog\Absences\Application\DTOs\ConflictingReservationList;
use Src\Catalog\Absences\Domain\ValueObjects\ConflictingReservation;
use Src\Reservations\Reservations\Application\DTOs\ReservationView;
use Src\Reservations\Reservations\Application\UseCases\ListReservationsHoldingTime;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class ReservationsReservationConflicts implements ReservationConflicts
{
    /** Receive the Reservations use case that lists the reservations holding time inside an interval. */
    public function __construct(private ListReservationsHoldingTime $listHolding) {}

    /** Read them through the Reservations module; the status is written as the API names it. */
    public function heldIn(string $collaboratorId, TimeInterval $interval, DateTimeImmutable $now, int $limit): ConflictingReservationList
    {
        $page = $this->listHolding->execute($collaboratorId, $interval, $now, $limit);

        return new ConflictingReservationList(
            array_map(fn (ReservationView $view): ConflictingReservation => new ConflictingReservation(
                $view->reservation->id->value,
                $view->reservation->interval->start,
                $view->reservation->interval->end,
                $view->serviceName,
                $view->customerName ?? '',
                $view->status === ReservationStatus::Temporary ? 'temporal' : 'confirmada',
            ), $page->items),
            $page->total,
        );
    }
}
