<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Domain\Contracts;

use Src\Catalog\Services\Domain\Entities\Service;
use Src\Catalog\Services\Domain\ValueObjects\ServiceId;

interface ServiceRepository
{
    /** Generate the identity for a new service. */
    public function nextId(): ServiceId;

    /** Persist a new or modified service in the current barbershop. */
    public function save(Service $service): void;

    /** Find a service of the current barbershop. */
    public function find(ServiceId $id): ?Service;

    /**
     * List every service of the current barbershop, active or not, ordered by name.
     *
     * @return list<Service>
     */
    public function all(): array;
}
