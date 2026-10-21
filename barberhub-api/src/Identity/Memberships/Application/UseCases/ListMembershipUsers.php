<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Application\UseCases;

use Src\Identity\Memberships\Domain\Contracts\MembershipRepository;
use Src\Identity\Memberships\Domain\ValueObjects\MembershipId;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class ListMembershipUsers
{
    /** Receive the membership repository port. */
    public function __construct(private MembershipRepository $memberships) {}

    /**
     * Tell which account holds each membership of a barbershop, indexed by membership; an unknown membership, or one of
     * another barbershop, is skipped. The reservations read it to show the name of the customer.
     *
     * @param  list<string>  $membershipIds
     * @return array<string, string>
     */
    public function execute(array $membershipIds, string $barbershopId): array
    {
        if ($membershipIds === []) {
            return [];
        }

        return array_map(
            fn (UserId $userId): string => $userId->value,
            $this->memberships->usersOf(array_map(fn (string $id): MembershipId => new MembershipId($id), $membershipIds), new BarbershopId($barbershopId)),
        );
    }
}
