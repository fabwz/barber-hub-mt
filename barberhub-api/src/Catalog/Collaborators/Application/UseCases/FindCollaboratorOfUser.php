<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\UseCases;

use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class FindCollaboratorOfUser
{
    /** Receive the collaborator repository port. */
    public function __construct(private CollaboratorRepository $collaborators) {}

    /** Give the identifier of the collaborator profile of an account in the current barbershop, or none if it has no agenda (CO-04). */
    public function execute(string $userId): ?string
    {
        return $this->collaborators->findByUser(new UserId($userId))?->id->value;
    }
}
