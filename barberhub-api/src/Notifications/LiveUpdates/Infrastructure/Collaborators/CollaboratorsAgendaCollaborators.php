<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Infrastructure\Collaborators;

use Src\Catalog\Collaborators\Application\UseCases\FindCollaboratorOfUser;
use Src\Notifications\LiveUpdates\Application\Contracts\AgendaCollaborators;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;

final readonly class CollaboratorsAgendaCollaborators implements AgendaCollaborators
{
    /** Receive the Collaborators use case that finds a profile and the scope that opens the transaction of a barbershop. */
    public function __construct(private FindCollaboratorOfUser $findCollaborator, private BarbershopProcessScope $scope) {}

    /**
     * Find the profile of the account in the barbershop through the Collaborators module. The route of the channels has no barbershop in its
     * address, so the use case that calls this has already verified that the person holds a role there before the barbershop is set.
     */
    public function profileOf(string $userId, string $barbershopId): ?string
    {
        return $this->scope->run($barbershopId, fn (): ?string => $this->findCollaborator->execute($userId));
    }
}
