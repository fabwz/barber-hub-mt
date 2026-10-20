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
            fn (Service $service): PublicService => new PublicService(
                $service->id->value,
                $service->name->value,
                $service->duration->minutes(),
                $service->price->colones(),
                $service->price->base()->colones,
                $service->price->tax()->colones,
            ),
            array_filter($this->services->all(), fn (Service $service): bool => $service->active),
        ));
    }
}
