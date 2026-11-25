<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\UseCases;

use Src\Catalog\Barbershops\Application\Contracts\BarbershopSummaryReader;
use Src\Catalog\Barbershops\Application\Contracts\CollaboratorProfiles;
use Src\Catalog\Barbershops\Application\Contracts\PersonMemberships;
use Src\Catalog\Barbershops\Application\DTOs\BarbershopSummary;
use Src\Catalog\Barbershops\Application\DTOs\MyBarbershop;

final readonly class ListMyBarbershops
{
    /** Receive the port that tells the roles of a person, the one that reads barbershops and the one that finds their collaborator profile. */
    public function __construct(
        private PersonMemberships $memberships,
        private BarbershopSummaryReader $barbershops,
        private CollaboratorProfiles $profiles,
    ) {}

    /**
     * List the barbershops where the person holds an active role, with that role, ordered by name (RF-43, UP-08). Only
     * the identifiers of the person's own memberships reach the reader, so no other barbershop can appear. The profile is looked up only where the role is owner or
     * collaborator, one barbershop at a time because `collaborators` has row-level security; a customer has no profile and costs no query.
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
            fn (BarbershopSummary $barbershop): MyBarbershop => new MyBarbershop($barbershop->id, $barbershop->name, $barbershop->link, $roles[$barbershop->id], $this->profileOf($userId, $barbershop->id, $roles[$barbershop->id])),
            $barbershops,
        ));
    }

    /** Find the collaborator profile of the person in a barbershop; a customer has none. */
    private function profileOf(string $userId, string $barbershopId, string $role): ?string
    {
        return $role === 'customer' ? null : $this->profiles->of($userId, $barbershopId);
    }
}
