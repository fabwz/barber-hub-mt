<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Application\DTOs;

final readonly class RegisterAbsenceCommand
{
    /**
     * Carry what the owner wrote: the collaborator, the first and the last date (YYYY-MM-DD), the hours of the first and the last
     * day (HH:MM, both or none) and an optional reason.
     */
    public function __construct(
        public string $collaboratorId,
        public string $startDate,
        public string $endDate,
        public ?string $startTime,
        public ?string $endTime,
        public ?string $reason,
    ) {}
}
