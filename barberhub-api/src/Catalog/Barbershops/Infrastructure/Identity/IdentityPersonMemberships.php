<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Identity;

use Src\Catalog\Barbershops\Application\Contracts\PersonMemberships;
use Src\Catalog\Barbershops\Application\DTOs\BarbershopRole;
use Src\Identity\Memberships\Application\DTOs\UserMembership;
use Src\Identity\Memberships\Application\UseCases\ListUserMemberships;

final readonly class IdentityPersonMemberships implements PersonMemberships
{
    /** Receive the Identity use case that lists the roles of a person. */
    public function __construct(private ListUserMemberships $listUserMemberships) {}

    /**
     * Read the active roles of the person through the Identity context.
     *
     * @return list<BarbershopRole>
     */
    public function activeOf(string $userId): array
    {
        return array_map(
            fn (UserMembership $membership): BarbershopRole => new BarbershopRole($membership->barbershopId, $membership->role),
            $this->listUserMemberships->execute($userId),
        );
    }
}
