<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\AttachNames;
use Src\Reservations\Reservations\Application\DTOs\ReservationView;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationFilter;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;

final readonly class ListUpcomingConfirmedReservations
{
    /** Receive the reservation repository port and the names attacher. */
    public function __construct(
        private ReservationRepository $reservations,
        private AttachNames $attachNames,
    ) {}

    /**
     * List, from the nearest to the farthest, the confirmed reservations of a collaborator of the current barbershop that
     * start at the instant or later, up to `$limit`, with the total of them. The Catalog reads it to refuse deactivating a
     * collaborator who still has reservations to attend (CO-03). The names of the customers come with it, never their contact.
     *
     * @return Page<ReservationView>
     */
    public function execute(string $collaboratorId, DateTimeImmutable $now, int $limit): Page
    {
        $filter = new ReservationFilter(null, new CollaboratorId($collaboratorId), ReservationStatus::Confirmed, null, $now, $now);

        return $this->attachNames->to($this->reservations->page($filter, new PageRequest(1, $limit)), true, $now);
    }
}
