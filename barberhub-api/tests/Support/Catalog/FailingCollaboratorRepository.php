<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use RuntimeException;
use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Catalog\Collaborators\Domain\Entities\Collaborator;
use Src\Catalog\Collaborators\Domain\ValueObjects\CollaboratorId;
use Src\Catalog\Collaborators\Domain\ValueObjects\OfferedServiceId;
use Src\Catalog\Collaborators\Infrastructure\Persistence\Repositories\EloquentCollaboratorRepository;
use Src\Shared\Domain\ValueObjects\UserId;

/** The real repository except that it cannot save a profile, to see what the creation of a collaborator leaves behind. */
final readonly class FailingCollaboratorRepository implements CollaboratorRepository
{
    public function __construct(private EloquentCollaboratorRepository $real) {}

    /** Generate an identity like the real repository. */
    public function nextId(): CollaboratorId
    {
        return $this->real->nextId();
    }

    /** Fail as a database that went down at that moment. */
    public function save(Collaborator $collaborator): void
    {
        throw new RuntimeException('The database went down while saving the profile.');
    }

    /** Find a collaborator like the real repository. */
    public function find(CollaboratorId $id): ?Collaborator
    {
        return $this->real->find($id);
    }

    /** Find the profile of an account like the real repository. */
    public function findByUser(UserId $userId): ?Collaborator
    {
        return $this->real->findByUser($userId);
    }

    /** Lock a collaborator like the real repository. */
    public function lock(CollaboratorId $id): bool
    {
        return $this->real->lock($id);
    }

    /**
     * List the collaborators like the real repository.
     *
     * @return list<Collaborator>
     */
    public function all(): array
    {
        return $this->real->all();
    }

    /**
     * List the collaborators who offer a service like the real repository.
     *
     * @return list<Collaborator>
     */
    public function activeOffering(OfferedServiceId $service): array
    {
        return $this->real->activeOffering($service);
    }
}
