<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application;

use Src\Notifications\Notices\Application\Contracts\AssistedRecipients;
use Src\Notifications\Notices\Application\Contracts\Recipients;
use Src\Notifications\Notices\Application\DTOs\Recipient;

/** Finds who a reservation writes to: the account behind its customer role, or the assisted client the owner registered (RF-12). */
final readonly class CustomerRecipient
{
    public function __construct(
        private Recipients $accounts,
        private AssistedRecipients $assisted,
    ) {}

    /** The person to write to, or null when nobody can receive it: no email, as an assisted client without one (CL-06). */
    public function of(?string $customerMembershipId, ?string $assistedClientId): ?Recipient
    {
        if ($assistedClientId !== null) {
            return $this->assisted->ofAssistedClient($assistedClientId);
        }

        return $customerMembershipId === null ? null : $this->accounts->ofMembership($customerMembershipId);
    }
}
