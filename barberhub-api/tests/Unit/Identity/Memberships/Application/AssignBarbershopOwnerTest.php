<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Memberships\Application;

use PHPUnit\Framework\TestCase;
use Src\Identity\Memberships\Application\DTOs\AssignBarbershopOwnerCommand;
use Src\Identity\Memberships\Application\UseCases\AssignBarbershopOwner;
use Src\Identity\Memberships\Domain\Exceptions\RoleAlreadyAssignedException;
use Src\Identity\Memberships\Domain\ValueObjects\Role;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;
use Tests\Support\Identity\InMemoryMembershipRepository;

final class AssignBarbershopOwnerTest extends TestCase
{
    private const string USER_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    private const string BARBERSHOP_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d22';

    private InMemoryMembershipRepository $memberships;

    private AssignBarbershopOwner $assignOwner;

    /** Wire the use case to an in-memory port. */
    protected function setUp(): void
    {
        $this->memberships = new InMemoryMembershipRepository;
        $this->assignOwner = new AssignBarbershopOwner($this->memberships);
    }

    /** The person becomes an active owner of the barbershop. */
    public function test_person_becomes_the_active_owner(): void
    {
        $membership = $this->assignOwner->execute(new AssignBarbershopOwnerCommand(self::BARBERSHOP_ID, self::USER_ID));

        $this->assertSame(Role::Owner, $membership->role);
        $this->assertTrue($membership->active);
        $this->assertEquals(
            $membership,
            $this->memberships->findByUserAndBarbershop(new UserId(self::USER_ID), new BarbershopId(self::BARBERSHOP_ID)),
        );
    }

    /** A person has a single role per barbershop (UP-07). */
    public function test_second_role_in_the_same_barbershop_is_refused(): void
    {
        $this->assignOwner->execute(new AssignBarbershopOwnerCommand(self::BARBERSHOP_ID, self::USER_ID));

        try {
            $this->assignOwner->execute(new AssignBarbershopOwnerCommand(self::BARBERSHOP_ID, self::USER_ID));
            $this->fail('A second role was accepted.');
        } catch (RoleAlreadyAssignedException) {
            $this->assertSame(1, $this->memberships->count());
        }
    }

    /** The same person can own another barbershop. */
    public function test_person_can_own_several_barbershops(): void
    {
        $this->assignOwner->execute(new AssignBarbershopOwnerCommand(self::BARBERSHOP_ID, self::USER_ID));
        $this->assignOwner->execute(new AssignBarbershopOwnerCommand('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d33', self::USER_ID));

        $this->assertSame(2, $this->memberships->count());
    }

    /** A malformed identifier is refused. */
    public function test_malformed_identifier_is_refused(): void
    {
        $this->expectException(InvalidValueException::class);

        $this->assignOwner->execute(new AssignBarbershopOwnerCommand('x', self::USER_ID));
    }
}
