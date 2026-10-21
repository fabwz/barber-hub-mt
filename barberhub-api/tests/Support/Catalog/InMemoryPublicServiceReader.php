<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use Src\Catalog\Services\Application\Contracts\PublicServiceReader;
use Src\Catalog\Services\Application\DTOs\PublicService;
use Src\Catalog\Services\Domain\Entities\Service;

final readonly class InMemoryPublicServiceReader implements PublicServiceReader
{
    /** Read the services of the repository the test saved them in. */
    public function __construct(private InMemoryServiceRepository $services) {}

    /**
     * Read the active services with their breakdown.
     *
     * @return list<PublicService>
     */
    public function activeServices(): array
    {
        return array_values(array_map(
            $this->toPublicService(...),
            array_filter($this->services->all(), fn (Service $service): bool => $service->active),
        ));
    }

    /** Read one active service with its breakdown. */
    public function activeService(string $serviceId): ?PublicService
    {
        foreach ($this->services->all() as $service) {
            if ($service->active && $service->id->value === $serviceId) {
                return $this->toPublicService($service);
            }
        }

        return null;
    }

    private function toPublicService(Service $service): PublicService
    {
        return new PublicService(
            $service->id->value,
            $service->name->value,
            $service->duration->minutes(),
            $service->price->colones(),
            $service->price->base()->colones,
            $service->price->tax()->colones,
        );
    }
}
