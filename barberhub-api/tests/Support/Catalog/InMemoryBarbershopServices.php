<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use Src\Catalog\Collaborators\Application\Contracts\BarbershopServices;

final class InMemoryBarbershopServices implements BarbershopServices
{
    /** @var list<string> */
    private array $known = [];

    /** Make a service exist in the barbershop. */
    public function add(string $serviceId): void
    {
        $this->known[] = $serviceId;
    }

    /**
     * Pick, among the given identifiers, the services that exist.
     *
     * @param  list<string>  $serviceIds
     * @return list<string>
     */
    public function existingAmong(array $serviceIds): array
    {
        return array_values(array_intersect($serviceIds, $this->known));
    }
}
