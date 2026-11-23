<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Application\UseCases;

use Src\Identity\Memberships\Domain\Contracts\MembershipRepository;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class ListBarbershopOwners
{
    /** Receive the membership repository port. */
    public function __construct(private MembershipRepository $memberships) {}

    /**
     * Tell which of the given accounts are the owner of a barbershop (an active owner role there), in one read. The Catalog
     * reads it to mark the profile of the owner among the collaborators (CO-02).
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public function execute(array $userIds, string $barbershopId): array
    {
        if ($userIds === []) {
            return [];
        }

        return array_map(
            fn (UserId $userId): string => $userId->value,
            $this->memberships->ownersAmong(array_map(fn (string $id): UserId => new UserId($id), $userIds), new BarbershopId($barbershopId)),
        );
    }
}
