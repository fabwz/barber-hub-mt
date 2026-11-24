<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Identity;

use Src\Identity\Memberships\Application\UseCases\ListMembershipUsers;
use Src\Identity\Users\Application\UseCases\ListAccountSummaries;
use Src\Notifications\Notices\Application\Contracts\Recipients;
use Src\Notifications\Notices\Application\DTOs\Recipient;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class IdentityRecipients implements Recipients
{
    /** Receive the Identity use cases that find the account of a customer role and read it, and the port that tells which barbershop it is. */
    public function __construct(
        private ListMembershipUsers $membershipUsers,
        private ListAccountSummaries $accounts,
        private BarbershopContext $context,
    ) {}

    /** Find the account behind the customer role of the current barbershop and answer its name and email; null when there is none. */
    public function ofMembership(string $membershipId): ?Recipient
    {
        $userId = $this->membershipUsers->execute([$membershipId], $this->context->current()->value)[$membershipId] ?? null;

        if ($userId === null) {
            return null;
        }

        $account = $this->accounts->execute([$userId])[0] ?? null;

        return $account === null || $account->email === '' ? null : new Recipient($account->name, $account->email);
    }
}
