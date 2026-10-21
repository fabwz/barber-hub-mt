<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Application\Contracts;

use Src\Catalog\Services\Application\DTOs\PublicService;

interface PublicServiceReader
{
    /**
     * Read the active services of the current barbershop, ordered by name.
     *
     * @return list<PublicService>
     */
    public function activeServices(): array;

    /** Read one active service of the current barbershop; none if it does not exist, belongs to another barbershop or is inactive. */
    public function activeService(string $serviceId): ?PublicService;
}
