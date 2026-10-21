<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Memberships;

use Src\Identity\Memberships\Application\UseCases\FindCustomerMembership;
use Src\Identity\Memberships\Application\UseCases\ListMembershipUsers;
use Src\Identity\Users\Application\UseCases\ListAccountSummaries;
use Src\Reservations\Reservations\Application\Contracts\CustomerDirectory;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class MembershipsCustomerDirectory implements CustomerDirectory
{
    /** Receive the Identity use cases that find a role, tell its account and describe accounts, and the barbershop port. */
    public function __construct(
        private FindCustomerMembership $findMembership,
        private ListMembershipUsers $listMembershipUsers,
        private ListAccountSummaries $listAccountSummaries,
        private BarbershopContext $context,
    ) {}

    /** Find the customer role of the person in the current barbershop through the Memberships module. */
    public function find(string $userId): ?string
    {
        return $this->findMembership->execute($userId, $this->context->current()->value);
    }

    /**
     * Read the accounts behind the roles of the current barbershop in two reads, one for the roles and one for the
     * accounts, and keep only the name.
     *
     * @param  list<string>  $membershipIds
     * @return array<string, string>
     */
    public function namesOf(array $membershipIds): array
    {
        $userOf = $this->listMembershipUsers->execute($membershipIds, $this->context->current()->value);
        $nameOf = [];

        foreach ($this->listAccountSummaries->execute(array_values(array_unique($userOf))) as $summary) {
            $nameOf[$summary->userId] = $summary->name;
        }

        $names = [];

        foreach ($userOf as $membershipId => $userId) {
            $names[$membershipId] = $nameOf[$userId] ?? '';
        }

        return $names;
    }
}
