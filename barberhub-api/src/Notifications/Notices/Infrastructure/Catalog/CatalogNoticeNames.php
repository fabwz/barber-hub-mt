<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Catalog;

use Src\Catalog\Collaborators\Application\DTOs\CollaboratorWithAccount;
use Src\Catalog\Collaborators\Application\UseCases\ListCollaborators;
use Src\Catalog\Services\Application\UseCases\ListServices;
use Src\Catalog\Services\Domain\Entities\Service;
use Src\Notifications\Notices\Application\Contracts\CatalogNames;

final readonly class CatalogNoticeNames implements CatalogNames
{
    /** Receive the Catalog use cases that list the services and the staff of the current barbershop. */
    public function __construct(
        private ListServices $listServices,
        private ListCollaborators $listCollaborators,
    ) {}

    /** Find the service among the ones of the barbershop and answer its name. */
    public function serviceName(string $serviceId): string
    {
        foreach ($this->listServices->execute() as $service) {
            /** @var Service $service */
            if ($service->id->value === $serviceId) {
                return $service->name->value;
            }
        }

        return '';
    }

    /** Find the collaborator among the staff of the barbershop and answer its name. */
    public function collaboratorName(string $collaboratorId): string
    {
        foreach ($this->listCollaborators->execute() as $member) {
            /** @var CollaboratorWithAccount $member */
            if ($member->collaborator->id->value === $collaboratorId) {
                return $member->name;
            }
        }

        return '';
    }
}
