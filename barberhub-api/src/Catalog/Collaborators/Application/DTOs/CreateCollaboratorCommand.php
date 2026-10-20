<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\DTOs;

use SensitiveParameter;

final readonly class CreateCollaboratorCommand
{
    /** Carry the data needed to create a collaborator with their account and a temporary password. */
    public function __construct(
        public string $name,
        public string $email,
        #[SensitiveParameter] public string $temporaryPassword,
    ) {}
}
