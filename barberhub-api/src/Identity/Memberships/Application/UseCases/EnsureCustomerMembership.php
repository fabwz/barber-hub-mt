<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Application\UseCases;

use Src\Identity\Memberships\Domain\Contracts\MembershipRepository;
use Src\Identity\Memberships\Domain\Entities\Membership;
use Src\Identity\Memberships\Domain\Exceptions\RoleAlreadyAssignedException;
use Src\Identity\Memberships\Domain\Exceptions\StaffCannotBeCustomerException;
use Src\Identity\Memberships\Domain\ValueObjects\Role;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class EnsureCustomerMembership
{
    /** Receive the membership repository port. */
    public function __construct(private MembershipRepository $memberships) {}

    /**
     * Give the identifier of the customer role of a person in a barbershop, creating it the first time (UP-09). It does
     * not open a transaction: the operation that needs it (a reservation) calls it inside its own, so the membership
     * disappears if that operation is rolled back. Two first requests of the same person at once end with one membership.
     *
     * @throws StaffCannotBeCustomerException when the person is an owner or a collaborator of the barbershop (UP-07)
     */
    public function execute(string $userId, string $barbershopId): string
    {
        $user = new UserId($userId);
        $barbershop = new BarbershopId($barbershopId);

        $existing = $this->memberships->findByUserAndBarbershop($user, $barbershop);

        if ($existing === null) {
            $membership = Membership::create($this->memberships->nextId(), $user, $barbershop, Role::Customer);

            try {
                $this->memberships->save($membership);

                return $membership->id->value;
            } catch (RoleAlreadyAssignedException $exception) {
                // Another request of the same person created a role first; its role decides.
                $existing = $this->memberships->findByUserAndBarbershop($user, $barbershop) ?? throw $exception;
            }
        }

        return $existing->role === Role::Customer ? $existing->id->value : throw StaffCannotBeCustomerException::create();
    }
}
