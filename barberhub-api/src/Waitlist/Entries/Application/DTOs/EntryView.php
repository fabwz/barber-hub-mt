<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\DTOs;

use Src\Waitlist\Entries\Domain\Entities\WaitlistEntry;

final readonly class EntryView
{
    /** Carry a row with the name of the collaborator it asked for, which the row itself does not know; none when it asked for any. */
    public function __construct(
        public WaitlistEntry $entry,
        public ?string $collaboratorName,
    ) {}
}
