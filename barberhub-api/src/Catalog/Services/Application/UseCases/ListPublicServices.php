<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Application\UseCases;

use Src\Catalog\Services\Application\Contracts\PublicServiceReader;
use Src\Catalog\Services\Application\DTOs\PublicService;

final readonly class ListPublicServices
{
    /** Receive the port that reads the services a customer may see. */
    public function __construct(private PublicServiceReader $reader) {}

    /**
     * List the active services of the current barbershop with their VAT breakdown (SV-04, RL-02).
     *
     * @return list<PublicService>
     */
    public function execute(): array
    {
        return $this->reader->activeServices();
    }
}
