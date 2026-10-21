<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\DTOs;

final readonly class BookableStaffMember
{
    /** Carry the name a customer sees for a collaborator who can be booked; the email and the other data of the account stay out. */
    public function __construct(
        public string $collaboratorId,
        public string $name,
    ) {}
}
