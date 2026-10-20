<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Application\UseCases;

use Src\Catalog\Services\Domain\Contracts\ServiceRepository;
use Src\Catalog\Services\Domain\Entities\Service;

final readonly class ListServices
{
    /** Receive the service repository port. */
    public function __construct(private ServiceRepository $services) {}

    /**
     * List every service of the current barbershop, active or not, for its owner.
     *
     * @return list<Service>
     */
    public function execute(): array
    {
        return $this->services->all();
    }
}
