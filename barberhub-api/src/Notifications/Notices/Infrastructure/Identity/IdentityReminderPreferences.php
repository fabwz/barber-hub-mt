<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Identity;

use Src\Identity\Memberships\Application\UseCases\ListMembershipUsers;
use Src\Identity\Users\Application\UseCases\FindReminderPreference;
use Src\Notifications\Notices\Application\Contracts\ReminderPreferences;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class IdentityReminderPreferences implements ReminderPreferences
{
    /** Receive the Identity use cases that find the account of a customer role and read its preference, and the port that tells which barbershop it is. */
    public function __construct(
        private ListMembershipUsers $membershipUsers,
        private FindReminderPreference $preference,
        private BarbershopContext $context,
    ) {}

    /** Read the preference of the account behind the customer role; a role without account has no one to turn them off. */
    public function isEnabledFor(string $membershipId): bool
    {
        $userId = $this->membershipUsers->execute([$membershipId], $this->context->current()->value)[$membershipId] ?? null;

        return $userId === null || $this->preference->execute($userId);
    }
}
