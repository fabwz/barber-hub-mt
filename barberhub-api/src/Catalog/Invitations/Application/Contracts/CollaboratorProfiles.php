<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Application\Contracts;

interface CollaboratorProfiles
{
    /**
     * Give an account the profile of a collaborator in the current barbershop, with the services the owner chose.
     *
     * @param  list<string>  $serviceIds
     */
    public function create(string $userId, array $serviceIds): void;
}
