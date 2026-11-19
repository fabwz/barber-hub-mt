<?php

declare(strict_types=1);

namespace Tests\Support\Vouchers;

use Src\Vouchers\VoucherDefinitions\Application\Contracts\CatalogServices;

final class InMemoryCatalogServices implements CatalogServices
{
    /** @var list<string> */
    private array $known = [];

    /** @var array<string, string> */
    private array $names = [];

    /** Make a service exist in the barbershop, with the name it is read by. */
    public function add(string $serviceId, string $name = ''): void
    {
        $this->known[] = $serviceId;
        $this->names[$serviceId] = $name === '' ? "Servicio {$serviceId}" : $name;
    }

    /**
     * Name the given services that exist.
     *
     * @param  list<string>  $serviceIds
     * @return array<string, string>
     */
    public function namesOf(array $serviceIds): array
    {
        return array_intersect_key($this->names, array_flip($serviceIds));
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
