<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\UseCases;

use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Catalog\Collaborators\Domain\Entities\Collaborator;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorAlreadyExistsException;
use Src\Catalog\Collaborators\Domain\ValueObjects\OfferedServiceId;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class CreateInvitedCollaboratorProfile
{
    /** Receive the collaborator repository port. */
    public function __construct(private CollaboratorRepository $collaborators) {}

    /**
     * Give the account of an accepted invitation (RF-06) its profile, active, with the services the owner chose and no working hours yet:
     * the owner sets the schedule afterwards (RF-05). The role is granted by whoever calls this; the profile is what has an agenda.
     *
     * @param  list<string>  $serviceIds  services of the barbershop, already checked when the invitation was made
     *
     * @throws CollaboratorAlreadyExistsException
     */
    public function execute(string $userId, array $serviceIds): void
    {
        $user = new UserId($userId);

        if ($this->collaborators->findByUser($user) !== null) {
            throw CollaboratorAlreadyExistsException::create();
        }

        $collaborator = Collaborator::create($this->collaborators->nextId(), $user);
        $collaborator->offer(array_map(fn (string $id): OfferedServiceId => new OfferedServiceId($id), $serviceIds));
        $this->collaborators->save($collaborator);
    }
}
