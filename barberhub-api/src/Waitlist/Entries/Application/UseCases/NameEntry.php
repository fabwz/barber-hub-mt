<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\UseCases;

use Src\Waitlist\Entries\Application\Contracts\CollaboratorNames;
use Src\Waitlist\Entries\Application\DTOs\EntryView;
use Src\Waitlist\Entries\Domain\Entities\WaitlistEntry;

final readonly class NameEntry
{
    /** Receive the port to the names of the collaborators. */
    public function __construct(private CollaboratorNames $collaborators) {}

    /** Carry a row with the name of the collaborator it asked for; a row for any collaborator has none, and no name is read for it. */
    public function execute(WaitlistEntry $entry): EntryView
    {
        if ($entry->collaboratorId === null) {
            return new EntryView($entry, null);
        }

        return new EntryView($entry, $this->collaborators->names()[$entry->collaboratorId->value] ?? null);
    }
}
