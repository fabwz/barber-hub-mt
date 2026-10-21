<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Application\UseCases;

use Src\Identity\Memberships\Domain\Contracts\MembershipRepository;
use Src\Identity\Memberships\Domain\ValueObjects\Role;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class FindCustomerMembership
{
    /** Receive the membership repository port. */
    public function __construct(private MembershipRepository $memberships) {}

    /**
     * Give the identifier of the customer role of a person in a barbershop, or none if they have not reserved there yet.
     * Unlike EnsureCustomerMembership it never creates the role: the reads of the person's own data go through it (RD-18).
     */
    public function execute(string $userId, string $barbershopId): ?string
    {
        $membership = $this->memberships->findByUserAndBarbershop(new UserId($userId), new BarbershopId($barbershopId));

        return $membership?->role === Role::Customer ? $membership->id->value : null;
    }
}
