<?php

declare(strict_types=1);

namespace Tests\Support\Availability;

use Src\Availability\Slots\Application\Contracts\ServiceCatalog;
use Src\Availability\Slots\Application\DTOs\BookableService;
use Src\Shared\Domain\ValueObjects\Duration;

final class InMemoryServiceCatalog implements ServiceCatalog
{
    public int $reads = 0;

    /** @param array<string, int> $minutes The duration of each bookable service by identifier. */
    public function __construct(private readonly array $minutes) {}

    /** Give the duration of a bookable service and count the read. */
    public function bookableDuration(string $serviceId): ?Duration
    {
        $this->reads++;

        return isset($this->minutes[$serviceId]) ? Duration::ofMinutes($this->minutes[$serviceId]) : null;
    }

    /** Give a bookable service priced at 8 475 colones, VAT included, and count the read. */
    public function bookableService(string $serviceId): ?BookableService
    {
        $this->reads++;

        return isset($this->minutes[$serviceId]) ? new BookableService($serviceId, 'Servicio', Duration::ofMinutes($this->minutes[$serviceId]), 8475, 7500, 975) : null;
    }
}
