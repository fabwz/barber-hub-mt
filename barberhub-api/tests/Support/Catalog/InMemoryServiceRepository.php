<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use Src\Catalog\Services\Domain\Contracts\ServiceRepository;
use Src\Catalog\Services\Domain\Entities\Service;
use Src\Catalog\Services\Domain\ValueObjects\ServiceId;

final class InMemoryServiceRepository implements ServiceRepository
{
    /** @var array<string, Service> */
    private array $services = [];

    private int $sequence = 0;

    /** Generate a predictable identity. */
    public function nextId(): ServiceId
    {
        return new ServiceId(sprintf('0192f0c4-0000-7000-8000-%012d', ++$this->sequence));
    }

    /** Store the service. */
    public function save(Service $service): void
    {
        $this->services[$service->id->value] = $service;
    }

    /** Find a service by its identity. */
    public function find(ServiceId $id): ?Service
    {
        return $this->services[$id->value] ?? null;
    }

    /**
     * List the stored services ordered by name.
     *
     * @return list<Service>
     */
    public function all(): array
    {
        $services = array_values($this->services);
        usort($services, fn (Service $a, Service $b): int => $a->name->value <=> $b->name->value);

        return $services;
    }
}
