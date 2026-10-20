<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Identity;

use Src\Catalog\Barbershops\Application\Contracts\OwnerMemberships;
use Src\Identity\Memberships\Application\DTOs\AssignBarbershopOwnerCommand;
use Src\Identity\Memberships\Application\UseCases\AssignBarbershopOwner;
use Src\Shared\Domain\ValueObjects\BarbershopId;

final readonly class IdentityOwnerMemberships implements OwnerMemberships
{
    /** Receive the Identity use case that assigns the owner role. */
    public function __construct(private AssignBarbershopOwner $assignBarbershopOwner) {}

    /** Make the person the owner of the barbershop through the Identity context. */
    public function assignOwner(BarbershopId $barbershopId, string $userId): void
    {
        $this->assignBarbershopOwner->execute(new AssignBarbershopOwnerCommand($barbershopId->value, $userId));
    }
}
