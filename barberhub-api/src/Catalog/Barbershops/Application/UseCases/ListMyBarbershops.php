<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\UseCases;

use Src\Catalog\Barbershops\Application\Contracts\BarbershopSummaryReader;
use Src\Catalog\Barbershops\Application\Contracts\PersonMemberships;
use Src\Catalog\Barbershops\Application\DTOs\BarbershopSummary;
use Src\Catalog\Barbershops\Application\DTOs\MyBarbershop;

final readonly class ListMyBarbershops
{
    /** Receive the port that tells the roles of a person and the one that reads barbershops. */
    public function __construct(private PersonMemberships $memberships, private BarbershopSummaryReader $barbershops) {}

    /**
     * List the barbershops where the person holds an active role, with that role, ordered by name (RF-43, UP-08). Only
     * the identifiers of the person's own memberships reach the reader, so no other barbershop can appear.
     *
     * @return list<MyBarbershop>
     */
    public function execute(string $userId): array
    {
        $roles = [];

        foreach ($this->memberships->activeOf($userId) as $membership) {
            $roles[$membership->barbershopId] = $membership->role;
        }

        if ($roles === []) {
            return [];
        }

        $barbershops = array_filter(
            $this->barbershops->findMany(array_map('strval', array_keys($roles))),
            fn (BarbershopSummary $barbershop): bool => isset($roles[$barbershop->id]),
        );

        return array_values(array_map(
            fn (BarbershopSummary $barbershop): MyBarbershop => new MyBarbershop($barbershop->id, $barbershop->name, $barbershop->link, $roles[$barbershop->id]),
            $barbershops,
        ));
    }
}
