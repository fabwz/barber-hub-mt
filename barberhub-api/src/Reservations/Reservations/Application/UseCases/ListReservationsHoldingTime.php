<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\AttachNames;
use Src\Reservations\Reservations\Application\DTOs\ReservationView;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationFilter;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class ListReservationsHoldingTime
{
    /** Receive the reservation repository port and the names attacher. */
    public function __construct(
        private ReservationRepository $reservations,
        private AttachNames $attachNames,
    ) {}

    /**
     * List, from the earliest to the latest, the reservations of a collaborator of the current barbershop that hold time inside an
     * interval, up to `$limit`, with the total of them: the confirmed ones and the temporary ones whose payment window is still open
     * at the instant, the same two that make a space unavailable (DI-01, RE-01). What a reservation holds is its service plus the
     * cleaning. The Catalog reads it to refuse blocking an agenda over reservations nobody resolved (CO-06). The names of the
     * customers come with it, never their contact.
     *
     * @return Page<ReservationView>
     */
    public function execute(string $collaboratorId, TimeInterval $interval, DateTimeImmutable $now, int $limit): Page
    {
        $filter = new ReservationFilter(null, new CollaboratorId($collaboratorId), null, null, $now, null, $interval);

        return $this->attachNames->to($this->reservations->page($filter, new PageRequest(1, $limit)), true, $now);
    }
}
