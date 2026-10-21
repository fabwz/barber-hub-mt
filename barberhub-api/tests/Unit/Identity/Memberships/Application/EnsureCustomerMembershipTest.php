<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Memberships\Application;

use PHPUnit\Framework\TestCase;
use Src\Identity\Memberships\Application\UseCases\EnsureCustomerMembership;
use Src\Identity\Memberships\Domain\Entities\Membership;
use Src\Identity\Memberships\Domain\Exceptions\StaffCannotBeCustomerException;
use Src\Identity\Memberships\Domain\ValueObjects\Role;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;
use Tests\Support\Identity\InMemoryMembershipRepository;

final class EnsureCustomerMembershipTest extends TestCase
{
    private const string USER_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    private const string BARBERSHOP_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d22';

    private const string OTHER_BARBERSHOP_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d33';

    private InMemoryMembershipRepository $memberships;

    private EnsureCustomerMembership $ensure;

    /** Wire the use case to an in-memory port. */
    protected function setUp(): void
    {
        $this->memberships = new InMemoryMembershipRepository;
        $this->ensure = new EnsureCustomerMembership($this->memberships);
    }

    /** The first use creates the role of customer (UP-09). */
    public function test_first_use_creates_the_customer_membership(): void
    {
        $id = $this->ensure->execute(self::USER_ID, self::BARBERSHOP_ID);

        $membership = $this->memberships->findByUserAndBarbershop(new UserId(self::USER_ID), new BarbershopId(self::BARBERSHOP_ID));
        $this->assertNotNull($membership);
        $this->assertSame($id, $membership->id->value);
        $this->assertSame(Role::Customer, $membership->role);
        $this->assertTrue($membership->active);
    }

    /** The next uses answer the same membership without creating another. */
    public function test_next_uses_return_the_same_membership(): void
    {
        $first = $this->ensure->execute(self::USER_ID, self::BARBERSHOP_ID);

        $this->assertSame($first, $this->ensure->execute(self::USER_ID, self::BARBERSHOP_ID));
        $this->assertSame(1, $this->memberships->count());
    }

    /** A role in another barbershop is not a role here: the person is a customer here (UP-07). */
    public function test_a_role_in_another_barbershop_does_not_matter(): void
    {
        $this->grant(Role::Owner, self::OTHER_BARBERSHOP_ID);

        $this->ensure->execute(self::USER_ID, self::BARBERSHOP_ID);

        $this->assertSame(2, $this->memberships->count());
    }

    /** An owner or a collaborator, active or not, cannot become the customer of the barbershop (UP-07). */
    public function test_staff_of_the_barbershop_is_refused(): void
    {
        foreach ([[Role::Owner, true], [Role::Collaborator, true], [Role::Collaborator, false]] as [$role, $active]) {
            $this->memberships = new InMemoryMembershipRepository;
            $this->ensure = new EnsureCustomerMembership($this->memberships);
            $this->grant($role, self::BARBERSHOP_ID, $active);

            try {
                $this->ensure->execute(self::USER_ID, self::BARBERSHOP_ID);
                $this->fail('A staff member became a customer.');
            } catch (StaffCannotBeCustomerException $exception) {
                $this->assertSame('prohibido', $exception->rule());
                $this->assertSame(1, $this->memberships->count());
            }
        }
    }

    /** Give the person a role in a barbershop. */
    private function grant(Role $role, string $barbershopId, bool $active = true): void
    {
        $membership = Membership::create($this->memberships->nextId(), new UserId(self::USER_ID), new BarbershopId($barbershopId), $role);
        $this->memberships->save($active ? $membership : $membership->deactivate());
    }
}
