<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\DTOs;

final readonly class ReplaceScheduleCommand
{
    /**
     * Carry the whole weekly schedule that replaces the one of a collaborator.
     *
     * @param  list<ScheduleEntry>  $shifts
     * @param  list<ScheduleEntry>  $breaks
     */
    public function __construct(
        public string $collaboratorId,
        public array $shifts,
        public array $breaks,
    ) {}
}
