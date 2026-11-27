<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Infrastructure\Collaborators;

use Src\Catalog\Collaborators\Application\UseCases\CreateInvitedCollaboratorProfile;
use Src\Catalog\Invitations\Application\Contracts\CollaboratorProfiles;

final readonly class CollaboratorsCollaboratorProfiles implements CollaboratorProfiles
{
    /** Receive the Collaborators use case that creates the profile of an invited account. */
    public function __construct(private CreateInvitedCollaboratorProfile $createProfile) {}

    /**
     * Create the profile through the Collaborators module.
     *
     * @param  list<string>  $serviceIds
     */
    public function create(string $userId, array $serviceIds): void
    {
        $this->createProfile->execute($userId, $serviceIds);
    }
}
