<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Memberships\Domain;

use PHPUnit\Framework\TestCase;
use Src\Identity\Memberships\Domain\Entities\Membership;
use Src\Identity\Memberships\Domain\Exceptions\RoleAlreadyAssignedException;
use Src\Identity\Memberships\Domain\ValueObjects\MembershipId;
use Src\Identity\Memberships\Domain\ValueObjects\Role;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

final class MembershipTest extends TestCase
{
    private const string MEMBERSHIP_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d11';

    private const string USER_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    private const string BARBERSHOP_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d22';

    /** A new membership starts active with the role it was granted. */
    public function test_new_membership_is_active(): void
    {
        $membership = Membership::create(
            new MembershipId(self::MEMBERSHIP_ID),
            new UserId(self::USER_ID),
            new BarbershopId(self::BARBERSHOP_ID),
            Role::Owner,
        );

        $this->assertSame(self::MEMBERSHIP_ID, $membership->id->value);
        $this->assertSame(self::USER_ID, $membership->userId->value);
        $this->assertSame(self::BARBERSHOP_ID, $membership->barbershopId->value);
        $this->assertSame(Role::Owner, $membership->role);
        $this->assertTrue($membership->active);
    }

    /** A stored membership keeps its state, even when it was deactivated. */
    public function test_reconstitute_keeps_the_stored_state(): void
    {
        $membership = Membership::reconstitute(
            new MembershipId(self::MEMBERSHIP_ID),
            new UserId(self::USER_ID),
            new BarbershopId(self::BARBERSHOP_ID),
            Role::Collaborator,
            false,
        );

        $this->assertSame(Role::Collaborator, $membership->role);
        $this->assertFalse($membership->active);
    }

    /** The three roles of UP-01 exist, and only the customer is not staff. */
    public function test_roles_and_staff(): void
    {
        $this->assertSame(['owner', 'collaborator', 'customer'], array_column(Role::cases(), 'value'));
        $this->assertTrue(Role::Owner->isStaff());
        $this->assertTrue(Role::Collaborator->isStaff());
        $this->assertFalse(Role::Customer->isStaff());
    }

    /** The duplicated role is a business conflict with its own translation key. */
    public function test_duplicated_role_is_a_conflict(): void
    {
        $exception = RoleAlreadyAssignedException::create();

        $this->assertSame('identity.role_already_assigned', $exception->translationKey());
        $this->assertSame('conflicto', $exception->rule());
    }
}
