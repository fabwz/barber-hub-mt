<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use Src\Reservations\Reservations\Application\AttachNames;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorDirectory;
use Src\Reservations\Reservations\Application\DTOs\ReservationSearch;
use Src\Reservations\Reservations\Application\DTOs\ReservationView;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationFilter;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;

final readonly class ListStaffReservations
{
    /** Receive the reservation repository, the port that tells the collaborators and the names attacher. */
    public function __construct(
        private ReservationRepository $reservations,
        private CollaboratorDirectory $collaborators,
        private AttachNames $attachNames,
    ) {}

    /**
     * List one page of the reservations of the current barbershop that the owner or a collaborator may see (RF-13). The
     * owner sees those of every collaborator and may narrow to one; a collaborator sees only those of their own profile
     * (CO-04) and, if they ask for another collaborator, gets the same not-found of a collaborator that does not exist
     * (UP-06). The owner asking for a collaborator that is not in the barbershop gets it too. A collaborator without a
     * profile has no agenda, so their page is empty. The names of the customers come with the page, never their contact.
     *
     * @return Page<ReservationView>
     *
     * @throws CollaboratorNotFoundException
     */
    public function execute(string $userId, bool $isOwner, ReservationSearch $search, PageRequest $page): Page
    {
        $collaboratorId = $isOwner ? $this->requestedByOwner($search->collaboratorId) : $this->ownAgenda($userId, $search->collaboratorId);

        if (! $isOwner && $collaboratorId === null) {
            return new Page([], 0, $page);
        }

        $filter = new ReservationFilter(null, $collaboratorId === null ? null : new CollaboratorId($collaboratorId), $search->status, $search->day);

        return $this->attachNames->to($this->reservations->page($filter, $page), true);
    }

    /** The collaborator the owner narrows to, which must be in the barbershop. */
    private function requestedByOwner(?string $requested): ?string
    {
        if ($requested !== null && ! array_key_exists($requested, $this->collaborators->names())) {
            throw CollaboratorNotFoundException::create();
        }

        return $requested;
    }

    /** The profile of the collaborator, the only agenda they may ask for; none if they have no profile. */
    private function ownAgenda(string $userId, ?string $requested): ?string
    {
        $own = $this->collaborators->idOfUser($userId);

        if ($requested !== null && $requested !== $own) {
            throw CollaboratorNotFoundException::create();
        }

        return $own;
    }
}
