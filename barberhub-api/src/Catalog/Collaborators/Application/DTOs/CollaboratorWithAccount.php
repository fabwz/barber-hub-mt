<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\DTOs;

use Src\Catalog\Collaborators\Domain\Entities\Collaborator;

final readonly class CollaboratorWithAccount
{
    /** Carry a collaborator profile with the name and email of its account. */
    public function __construct(
        public Collaborator $collaborator,
        public string $name,
        public string $email,
    ) {}
}
