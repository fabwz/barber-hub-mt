<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Memberships;

use Src\Identity\Memberships\Application\UseCases\EnsureCustomerMembership;
use Src\Identity\Memberships\Application\UseCases\FindCustomerMembership;
use Src\Identity\Memberships\Application\UseCases\ListMembershipUsers;
use Src\Identity\Users\Application\UseCases\ListAccountSummaries;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\CustomerMemberships;

final readonly class MembershipsCustomerMemberships implements CustomerMemberships
{
    /**
     * Receive the Memberships use cases that give, find and tell the account of a customer role, the Users one that describes
     * accounts, and the port that tells which barbershop the operation belongs to.
     */
    public function __construct(
        private EnsureCustomerMembership $ensureMembership,
        private FindCustomerMembership $findMembership,
        private ListMembershipUsers $listMembershipUsers,
        private ListAccountSummaries $listAccountSummaries,
        private BarbershopContext $context,
    ) {}

    /** Give the customer role of the person in the current barbershop through the Memberships module, creating it the first time. */
    public function ensure(string $userId): string
    {
        return $this->ensureMembership->execute($userId, $this->context->current()->value);
    }

    /** Find the customer role of the person in the current barbershop through the Memberships module, creating nothing. */
    public function find(string $userId): ?string
    {
        return $this->findMembership->execute($userId, $this->context->current()->value);
    }

    /**
     * Read the accounts behind the roles of the current barbershop in two reads, one for the roles and one for the accounts, and
     * keep only the name.
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
