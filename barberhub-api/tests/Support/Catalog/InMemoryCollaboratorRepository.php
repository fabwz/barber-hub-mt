<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use RuntimeException;
use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Catalog\Collaborators\Domain\Entities\Collaborator;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorAlreadyExistsException;
use Src\Catalog\Collaborators\Domain\ValueObjects\CollaboratorId;
use Src\Catalog\Collaborators\Domain\ValueObjects\OfferedServiceId;
use Src\Shared\Domain\ValueObjects\UserId;

final class InMemoryCollaboratorRepository implements CollaboratorRepository
{
    /** Make the next save fail, as a database that is down would. */
    public bool $failOnSave = false;

    /** @var array<string, Collaborator> */
    private array $collaborators = [];

    private int $sequence = 0;

    /** Generate a predictable identity, in creation order. */
    public function nextId(): CollaboratorId
    {
        return new CollaboratorId(sprintf('0192f0c4-0000-7000-8000-%012d', ++$this->sequence));
    }

    /** Store the collaborator enforcing one profile per account. */
    public function save(Collaborator $collaborator): void
    {
        if ($this->failOnSave) {
            throw new RuntimeException('The database is down.');
        }

        $existing = $this->findByUser($collaborator->userId);

        if ($existing !== null && ! $existing->id->equals($collaborator->id)) {
            throw CollaboratorAlreadyExistsException::create();
        }

        $this->collaborators[$collaborator->id->value] = $collaborator;
    }

    /** Find a collaborator by its identity. */
    public function find(CollaboratorId $id): ?Collaborator
    {
        return $this->collaborators[$id->value] ?? null;
    }

    /** Find the profile of an account. */
    public function findByUser(UserId $userId): ?Collaborator
    {
        return array_find($this->collaborators, fn (Collaborator $collaborator): bool => $collaborator->userId->equals($userId));
    }

    /**
     * List the stored collaborators from the oldest to the newest.
     *
     * @return list<Collaborator>
     */
    public function all(): array
    {
        return array_values($this->collaborators);
    }

    /**
     * List the active collaborators who offer the service.
     *
     * @return list<Collaborator>
     */
    public function activeOffering(OfferedServiceId $service): array
    {
        return array_values(array_filter(
            $this->collaborators,
            fn (Collaborator $collaborator): bool => $collaborator->active && $collaborator->offers($service),
        ));
    }

    /** Count the stored collaborators. */
    public function count(): int
    {
        return count($this->collaborators);
    }
}
