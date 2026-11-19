<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\Contracts;

interface WaitlistSettings
{
    /** Tell whether the current barbershop has the waitlist switched on, so a customer can join it (RF-09, LE-01). */
    public function isEnabled(): bool;
}
