<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Infrastructure\Catalog;

use Src\Catalog\Collaborators\Application\UseCases\GetCollaborator;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Catalog\Services\Application\UseCases\GetPublicService;
use Src\Waitlist\Entries\Application\Contracts\WaitlistCatalog;

final readonly class CatalogWaitlistCatalog implements WaitlistCatalog
{
    /** Receive the Services use case that gives an active service and the Collaborators one that gives a collaborator. */
    public function __construct(
        private GetPublicService $getService,
        private GetCollaborator $getCollaborator,
    ) {}

    /** Whether the service is an active one of the current barbershop, through the Services module. */
    public function hasService(string $serviceId): bool
    {
        return $this->getService->execute($serviceId) !== null;
    }

    /** Whether the collaborator is one of the current barbershop, through the Collaborators module. */
    public function hasCollaborator(string $collaboratorId): bool
    {
        try {
            $this->getCollaborator->execute($collaboratorId);

            return true;
        } catch (CollaboratorNotFoundException) {
            return false;
        }
    }
}
