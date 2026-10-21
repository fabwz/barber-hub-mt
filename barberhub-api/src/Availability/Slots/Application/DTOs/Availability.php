<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\DTOs;

final readonly class Availability
{
    /**
     * Carry the answer to a customer: the service, the asked day and, when it has no spaces, the next days that do.
     *
     * @param  list<DayAvailability>  $nextDays
     */
    public function __construct(
        public BookableService $service,
        public DayAvailability $day,
        public array $nextDays,
    ) {}
}
