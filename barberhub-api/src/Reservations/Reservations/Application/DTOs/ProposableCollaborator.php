<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

/** An active collaborator who offers a service, as a customer sees them: the identifier and the name, never the account (IA-06). */
final readonly class ProposableCollaborator
{
    /** Carry the identifier and the name. */
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
