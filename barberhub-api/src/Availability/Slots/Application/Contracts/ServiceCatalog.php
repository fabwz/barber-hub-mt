<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\Contracts;

use Src\Availability\Slots\Application\DTOs\BookableService;
use Src\Shared\Domain\ValueObjects\Duration;

interface ServiceCatalog
{
    /** The duration of a service of the current barbershop that can be booked; none if it does not exist or is inactive (SV-04). */
    public function bookableDuration(string $serviceId): ?Duration;

    /** The service of the current barbershop that can be booked, with its price; none if it does not exist or is inactive (SV-04). */
    public function bookableService(string $serviceId): ?BookableService;
}
