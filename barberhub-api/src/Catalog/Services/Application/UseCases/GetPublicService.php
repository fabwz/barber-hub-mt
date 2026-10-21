<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Application\UseCases;

use Src\Catalog\Services\Application\Contracts\PublicServiceReader;
use Src\Catalog\Services\Application\DTOs\PublicService;

final readonly class GetPublicService
{
    /** Receive the port that reads the services a customer may see. */
    public function __construct(private PublicServiceReader $reader) {}

    /** Give an active service of the current barbershop with its VAT breakdown; none if it cannot be booked (SV-04, RL-02). The availability reads it. */
    public function execute(string $serviceId): ?PublicService
    {
        return $this->reader->activeService($serviceId);
    }
}
