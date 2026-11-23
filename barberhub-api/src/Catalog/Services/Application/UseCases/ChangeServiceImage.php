<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Application\UseCases;

use Src\Catalog\Services\Application\DTOs\ChangeServiceImageCommand;
use Src\Catalog\Services\Domain\Contracts\ServiceRepository;
use Src\Catalog\Services\Domain\Entities\Service;
use Src\Catalog\Services\Domain\Exceptions\ServiceNotFoundException;
use Src\Catalog\Services\Domain\ValueObjects\ServiceId;
use Src\Shared\Domain\Contracts\BarbershopFileStorage;
use Throwable;

final readonly class ChangeServiceImage
{
    /** Receive the service repository and the storage of the files of the barbershop. */
    public function __construct(
        private ServiceRepository $services,
        private BarbershopFileStorage $files,
    ) {}

    /**
     * Keep the new image of a service of the current barbershop and delete the one it replaces (RF-46, SV-07). A service of another
     * barbershop is not found, so its image is never touched (MT-05). If the row cannot be saved, the new file is removed.
     */
    public function execute(ChangeServiceImageCommand $command): Service
    {
        $service = $this->services->find(new ServiceId($command->serviceId)) ?? throw ServiceNotFoundException::create();

        $path = $this->files->store('servicios', $command->imagePath, $command->extension);

        try {
            $previous = $service->changeImage($path);
            $this->services->save($service);
        } catch (Throwable $exception) {
            $this->files->delete($path);

            throw $exception;
        }

        if ($previous !== null) {
            $this->files->delete($previous);
        }

        return $service;
    }
}
