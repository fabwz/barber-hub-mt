<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Services;

use Src\Catalog\Services\Application\UseCases\GetPublicService;
use Src\Reservations\Reservations\Application\Contracts\BookableServices;
use Src\Reservations\Reservations\Application\DTOs\BookableService;
use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\Money;

final readonly class ServicesBookableServices implements BookableServices
{
    /** Receive the Services use case that gives a service a customer may book. */
    public function __construct(private GetPublicService $getService) {}

    /** Give the price and duration of an active service of the current barbershop through the Services module. */
    public function find(string $serviceId): ?BookableService
    {
        $service = $this->getService->execute($serviceId);

        return $service === null ? null : new BookableService(Money::of($service->price), Duration::ofMinutes($service->durationMinutes));
    }
}
