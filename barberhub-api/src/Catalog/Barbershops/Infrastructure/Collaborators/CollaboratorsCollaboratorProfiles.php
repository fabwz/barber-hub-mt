<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Collaborators;

use Src\Catalog\Barbershops\Application\Contracts\CollaboratorProfiles;
use Src\Catalog\Collaborators\Application\UseCases\FindCollaboratorOfUser;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;

final readonly class CollaboratorsCollaboratorProfiles implements CollaboratorProfiles
{
    /** Receive the Collaborators use case that finds a profile and the scope that opens the transaction of a barbershop. */
    public function __construct(private FindCollaboratorOfUser $findCollaborator, private BarbershopProcessScope $scope) {}

    /**
     * Find the profile through the Collaborators module inside the transaction of that barbershop, because `collaborators` has row-level
     * security. The route has no barbershop in its address, so the caller only passes barbershops taken from the memberships of the person.
     */
    public function of(string $userId, string $barbershopId): ?string
    {
        return $this->scope->run($barbershopId, fn (): ?string => $this->findCollaborator->execute($userId));
    }
}
