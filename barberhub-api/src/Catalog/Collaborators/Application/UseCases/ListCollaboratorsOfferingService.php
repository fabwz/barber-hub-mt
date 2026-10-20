<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\UseCases;

use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Catalog\Collaborators\Domain\Entities\Collaborator;
use Src\Catalog\Collaborators\Domain\ValueObjects\OfferedServiceId;

final readonly class ListCollaboratorsOfferingService
{
    /** Receive the collaborator repository port. */
    public function __construct(private CollaboratorRepository $collaborators) {}

    /**
     * List the active collaborators who offer a service, from the oldest to the newest; the availability of #53 reads it.
     * A collaborator without the service enabled never shows up for it (CO-01).
     *
     * @return list<Collaborator>
     */
    public function execute(string $serviceId): array
    {
        return $this->collaborators->activeOffering(new OfferedServiceId($serviceId));
    }
}
