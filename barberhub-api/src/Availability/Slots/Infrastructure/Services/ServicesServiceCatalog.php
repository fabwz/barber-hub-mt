<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Infrastructure\Services;

use Src\Availability\Slots\Application\Contracts\ServiceCatalog;
use Src\Catalog\Services\Application\UseCases\GetBookableDuration;
use Src\Shared\Domain\ValueObjects\Duration;

final readonly class ServicesServiceCatalog implements ServiceCatalog
{
    /** Receive the Services use case that gives the duration of a bookable service. */
    public function __construct(private GetBookableDuration $getDuration) {}

    /** Give the duration of a bookable service through the Services module. */
    public function bookableDuration(string $serviceId): ?Duration
    {
        return $this->getDuration->execute($serviceId);
    }
}
