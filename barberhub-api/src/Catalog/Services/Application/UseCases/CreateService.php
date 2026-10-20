<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Application\UseCases;

use Src\Catalog\Services\Application\DTOs\CreateServiceCommand;
use Src\Catalog\Services\Domain\Contracts\ServiceRepository;
use Src\Catalog\Services\Domain\Entities\Service;
use Src\Catalog\Services\Domain\ValueObjects\ServiceDuration;
use Src\Catalog\Services\Domain\ValueObjects\ServiceName;
use Src\Catalog\Services\Domain\ValueObjects\ServicePrice;

final readonly class CreateService
{
    /** Receive the service repository port. */
    public function __construct(private ServiceRepository $services) {}

    /** Register a new active service in the current barbershop (RF-03). */
    public function execute(CreateServiceCommand $command): Service
    {
        $service = Service::create(
            $this->services->nextId(),
            new ServiceName($command->name),
            new ServiceDuration($command->durationMinutes),
            new ServicePrice($command->price),
        );

        $this->services->save($service);

        return $service;
    }
}
