<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Application\UseCases;

use Src\Catalog\Services\Domain\Contracts\ServiceRepository;
use Src\Catalog\Services\Domain\ValueObjects\ServiceId;
use Src\Shared\Domain\ValueObjects\Duration;

final readonly class GetBookableDuration
{
    /** Receive the service repository port. */
    public function __construct(private ServiceRepository $services) {}

    /** Give the duration of a service of the current barbershop that can be booked; none if it does not exist or is inactive (SV-04). The availability reads it. */
    public function execute(string $serviceId): ?Duration
    {
        $service = $this->services->find(new ServiceId($serviceId));

        return $service !== null && $service->active ? $service->duration->duration : null;
    }
}
