<?php

declare(strict_types=1);

namespace Tests\Support\Waitlist;

use Src\Identity\Memberships\Domain\Exceptions\StaffCannotBeCustomerException;
use Src\Waitlist\Entries\Application\Contracts\CustomerMemberships;

/** The customer roles of the test: it creates the first one of a person and refuses the staff. */
final class InMemoryWaitlistMemberships implements CustomerMemberships
{
    /** @var array<string, string> */
    private array $roles = [];

    /** @var list<string> */
    private array $staff = [];

    private int $sequence = 0;

    /** Make the person an owner or a collaborator of the barbershop. */
    public function makeStaff(string $userId): void
    {
        $this->staff[] = $userId;
    }

    /** Give the customer role of the person, creating it the first time. */
    public function ensure(string $userId): string
    {
        if (in_array($userId, $this->staff, true)) {
            throw StaffCannotBeCustomerException::create();
        }

        return $this->roles[$userId] ??= sprintf('0192f0c4-2222-7000-8000-%012d', ++$this->sequence);
    }

    /** The customer role of the person, if they have one. */
    public function find(string $userId): ?string
    {
        return $this->roles[$userId] ?? null;
    }

    /** How many customer roles were created. */
    public function created(): int
    {
        return count($this->roles);
    }
}
