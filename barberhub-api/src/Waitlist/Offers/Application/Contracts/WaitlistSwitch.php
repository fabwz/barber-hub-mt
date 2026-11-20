<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\Contracts;

interface WaitlistSwitch
{
    /** Whether the current barbershop has the waitlist switched on (RF-09): with it off, no new offer is made; the ones in course go on until they expire. */
    public function isEnabled(): bool;
}
