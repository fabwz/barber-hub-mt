<?php

declare(strict_types=1);

namespace Tests\Support\Reservations;

use Src\Reservations\Reservations\Application\Contracts\CollaboratorLock;

/** A lock that locks nothing, to show in a test what happens when the requests of a collaborator are not decided one by one. */
final readonly class NoCollaboratorLock implements CollaboratorLock
{
    /** Do nothing. */
    public function lock(string $collaboratorId): void {}
}
