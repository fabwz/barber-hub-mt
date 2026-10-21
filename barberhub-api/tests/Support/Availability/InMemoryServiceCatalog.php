<?php

declare(strict_types=1);

namespace Tests\Support\Availability;

use Src\Availability\Slots\Application\Contracts\ServiceCatalog;
use Src\Shared\Domain\ValueObjects\Duration;

final class InMemoryServiceCatalog implements ServiceCatalog
{
    /** @param array<string, int> $minutes The duration of each bookable service by identifier. */
    public function __construct(private readonly array $minutes) {}

    /** Give the duration of a bookable service. */
    public function bookableDuration(string $serviceId): ?Duration
    {
        return isset($this->minutes[$serviceId]) ? Duration::ofMinutes($this->minutes[$serviceId]) : null;
    }
}
