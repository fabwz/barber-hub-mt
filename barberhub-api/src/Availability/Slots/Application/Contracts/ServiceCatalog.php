<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\Contracts;

use Src\Shared\Domain\ValueObjects\Duration;

interface ServiceCatalog
{
    /** The duration of a service of the current barbershop that can be booked; none if it does not exist or is inactive (SV-04). */
    public function bookableDuration(string $serviceId): ?Duration;
}
