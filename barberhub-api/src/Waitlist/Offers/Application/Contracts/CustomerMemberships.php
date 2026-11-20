<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\Contracts;

interface CustomerMemberships
{
    /** Find the customer role of a person in the current barbershop, creating nothing: a person without one has no offers, and the answer is as for a missing one (RD-18, CL-04). */
    public function find(string $userId): ?string;
}
