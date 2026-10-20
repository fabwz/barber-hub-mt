<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Application\UseCases;

use Src\Catalog\Services\Application\DTOs\UpdateServiceCommand;
use Src\Catalog\Services\Domain\Contracts\ServiceRepository;
use Src\Catalog\Services\Domain\Entities\Service;
use Src\Catalog\Services\Domain\Exceptions\ServiceNotFoundException;
use Src\Catalog\Services\Domain\ValueObjects\ServiceDuration;
use Src\Catalog\Services\Domain\ValueObjects\ServiceId;
use Src\Catalog\Services\Domain\ValueObjects\ServiceName;
use Src\Catalog\Services\Domain\ValueObjects\ServicePrice;

final readonly class UpdateService
{
    /** Receive the service repository port. */
    public function __construct(private ServiceRepository $services) {}

    /** Apply the requested changes to a service of the current barbershop, including activating or deactivating it (RF-03). */
    public function execute(UpdateServiceCommand $command): Service
    {
        $service = $this->services->find(new ServiceId($command->serviceId)) ?? throw ServiceNotFoundException::create();

        $service->revise(
            $command->name === null ? $service->name : new ServiceName($command->name),
            $command->durationMinutes === null ? $service->duration : new ServiceDuration($command->durationMinutes),
            $command->price === null ? $service->price : new ServicePrice($command->price),
        );

        match ($command->active) {
            true => $service->activate(),
            false => $service->deactivate(),
            null => null,
        };

        $this->services->save($service);

        return $service;
    }
}
