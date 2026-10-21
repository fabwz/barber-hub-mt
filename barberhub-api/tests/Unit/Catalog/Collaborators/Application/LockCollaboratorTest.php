<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Collaborators\Application;

use PHPUnit\Framework\TestCase;
use Src\Catalog\Collaborators\Application\UseCases\LockCollaborator;
use Src\Catalog\Collaborators\Domain\Entities\Collaborator;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Shared\Domain\ValueObjects\UserId;
use Tests\Support\Catalog\InMemoryCollaboratorRepository;

final class LockCollaboratorTest extends TestCase
{
    private const string USER_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    private InMemoryCollaboratorRepository $collaborators;

    private LockCollaborator $lock;

    /** Wire the use case to an in-memory port. */
    protected function setUp(): void
    {
        $this->collaborators = new InMemoryCollaboratorRepository;
        $this->lock = new LockCollaborator($this->collaborators);
    }

    /** A collaborator of the barbershop is locked without an error. */
    public function test_an_existing_collaborator_is_locked(): void
    {
        $collaborator = Collaborator::create($this->collaborators->nextId(), new UserId(self::USER_ID));
        $this->collaborators->save($collaborator);

        $this->lock->execute($collaborator->id->value);

        $this->addToAssertionCount(1);
    }

    /** One that is not in the barbershop is not found. */
    public function test_an_unknown_collaborator_is_not_found(): void
    {
        $this->expectException(CollaboratorNotFoundException::class);

        $this->lock->execute('0192f0c4-0000-7000-8000-000000000099');
    }
}
