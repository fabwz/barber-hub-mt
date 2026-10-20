<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Domain\Contracts;

use Src\Catalog\Collaborators\Domain\Entities\Collaborator;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorAlreadyExistsException;
use Src\Catalog\Collaborators\Domain\Exceptions\ScheduleEntryException;
use Src\Catalog\Collaborators\Domain\ValueObjects\CollaboratorId;
use Src\Catalog\Collaborators\Domain\ValueObjects\OfferedServiceId;
use Src\Shared\Domain\ValueObjects\UserId;

interface CollaboratorRepository
{
    /** Generate the identity for a new collaborator. */
    public function nextId(): CollaboratorId;

    /**
     * Persist a new or modified collaborator in the current barbershop, with its services and weekly schedule.
     *
     * @throws CollaboratorAlreadyExistsException
     * @throws ScheduleEntryException
     */
    public function save(Collaborator $collaborator): void;

    /** Find a collaborator of the current barbershop. */
    public function find(CollaboratorId $id): ?Collaborator;

    /** Find the profile of an account in the current barbershop. */
    public function findByUser(UserId $userId): ?Collaborator;

    /**
     * List every collaborator of the current barbershop, active or not, from the oldest to the newest.
     *
     * @return list<Collaborator>
     */
    public function all(): array;

    /**
     * List the active collaborators of the current barbershop who offer a service, from the oldest to the newest (DI-04).
     *
     * @return list<Collaborator>
     */
    public function activeOffering(OfferedServiceId $service): array;
}
