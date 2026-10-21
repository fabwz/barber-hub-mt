<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use Src\Reservations\Reservations\Application\AttachNames;
use Src\Reservations\Reservations\Application\Contracts\AuthorDirectory;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorDirectory;
use Src\Reservations\Reservations\Application\DTOs\ReservationDetail;
use Src\Reservations\Reservations\Application\DTOs\ReservationHistoryEntry;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Exceptions\ReservationNotFoundException;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatusChange;

final readonly class GetStaffReservation
{
    /** Receive the reservation repository, the ports that tell the collaborators and the authors, and the names attacher. */
    public function __construct(
        private ReservationRepository $reservations,
        private CollaboratorDirectory $collaborators,
        private AuthorDirectory $authors,
        private AttachNames $attachNames,
    ) {}

    /**
     * Show the owner or a collaborator one reservation of the current barbershop (RF-14). Both see the balance still to
     * pay, which until payments exist is the whole price because it is paid at the premises (PA-10, CO-04); only the owner
     * gets the history with the name of each author (RE-09). A collaborator reads only their own agenda; a reservation of
     * another collaborator reads like one that does not exist (UP-06). The authors come in one read, never one per entry.
     *
     * @throws ReservationNotFoundException
     */
    public function execute(string $userId, bool $isOwner, string $reservationId): ReservationDetail
    {
        $id = new ReservationId($reservationId);
        $reservation = $this->reservations->find($id);

        if ($reservation === null || (! $isOwner && $reservation->collaboratorId->value !== $this->collaborators->idOfUser($userId))) {
            throw ReservationNotFoundException::create();
        }

        return new ReservationDetail(
            $this->attachNames->one($reservation, true),
            $reservation->price->colones,
            $isOwner ? $this->entriesOf($this->reservations->history($id)) : null,
        );
    }

    /**
     * Name the author of each change; the system has no name.
     *
     * @param  list<ReservationStatusChange>  $changes
     * @return list<ReservationHistoryEntry>
     */
    private function entriesOf(array $changes): array
    {
        $userIds = [];

        foreach ($changes as $change) {
            if ($change->author->user !== null) {
                $userIds[] = $change->author->user->value;
            }
        }

        $names = $this->authors->namesOf($userIds);

        return array_map(
            fn (ReservationStatusChange $change): ReservationHistoryEntry => new ReservationHistoryEntry(
                $change->from,
                $change->to,
                $change->author->user === null ? null : $names[$change->author->user->value] ?? '',
                $change->at,
            ),
            $changes,
        );
    }
}
