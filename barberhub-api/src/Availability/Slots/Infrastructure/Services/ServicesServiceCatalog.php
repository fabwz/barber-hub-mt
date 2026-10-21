<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Infrastructure\Services;

use Src\Availability\Slots\Application\Contracts\ServiceCatalog;
use Src\Availability\Slots\Application\DTOs\BookableService;
use Src\Catalog\Services\Application\UseCases\GetBookableDuration;
use Src\Catalog\Services\Application\UseCases\GetPublicService;
use Src\Shared\Domain\ValueObjects\Duration;

final readonly class ServicesServiceCatalog implements ServiceCatalog
{
    /** Receive the Services use cases that give a bookable service. */
    public function __construct(
        private GetBookableDuration $getDuration,
        private GetPublicService $getService,
    ) {}

    /** Give the duration of a bookable service through the Services module. */
    public function bookableDuration(string $serviceId): ?Duration
    {
        return $this->getDuration->execute($serviceId);
    }

    /** Give a bookable service with its price through the Services module. */
    public function bookableService(string $serviceId): ?BookableService
    {
        $service = $this->getService->execute($serviceId);

        return $service === null ? null : new BookableService(
            $service->id,
            $service->name,
            Duration::ofMinutes($service->durationMinutes),
            $service->price,
            $service->base,
            $service->tax,
        );
    }
}
