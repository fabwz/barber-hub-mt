<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\DTOs;

final readonly class JoinWaitlistCommand
{
    /** Carry what a person asks for: a service on a day, with one collaborator or with any (null). */
    public function __construct(
        public string $userId,
        public string $serviceId,
        public ?string $collaboratorId,
        public string $date,
    ) {}
}
