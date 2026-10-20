<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\DTOs;

final readonly class AccountDescription
{
    /** Carry the name and email of the account behind a collaborator. */
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
