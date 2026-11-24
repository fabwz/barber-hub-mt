<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\Contracts;

use Src\Notifications\Notices\Application\DTOs\Recipient;

/** Who a customer role is and where to write to them. */
interface Recipients
{
    /** The person of a customer role of the current barbershop; null when they have no email, as a customer created by the barbershop may (CL-06). */
    public function ofMembership(string $membershipId): ?Recipient;
}
