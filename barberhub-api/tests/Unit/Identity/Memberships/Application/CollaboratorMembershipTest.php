<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Memberships\Application;

use PHPUnit\Framework\TestCase;
use Src\Identity\Memberships\Application\DTOs\AssignBarbershopOwnerCommand;
use Src\Identity\Memberships\Application\DTOs\CollaboratorMembershipCommand;
use Src\Identity\Memberships\Application\UseCases\ActivateCollaboratorMembership;
use Src\Identity\Memberships\Application\UseCases\AssignBarbershopOwner;
use Src\Identity\Memberships\Application\UseCases\DeactivateCollaboratorMembership;
use Src\Identity\Memberships\Application\UseCases\GrantCollaboratorMembership;
use Src\Identity\Memberships\Domain\Entities\Membership;
use Src\Identity\Memberships\Domain\Exceptions\RoleAlreadyAssignedException;
use Src\Identity\Memberships\Domain\ValueObjects\Role;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;
use Tests\Support\Identity\InMemoryMembershipRepository;

final class CollaboratorMembershipTest extends TestCase
{
    private const string USER_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    private const string BARBERSHOP_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d22';

    private InMemoryMembershipRepository $memberships;

    /** Wire the use cases to an in-memory port. */
    protected function setUp(): void
    {
        $this->memberships = new InMemoryMembershipRepository;
    }

    /** The person becomes an active collaborator. */
    public function test_person_becomes_an_active_collaborator(): void
    {
        $membership = $this->grant();

        $this->assertSame(Role::Collaborator, $membership->role);
        $this->assertTrue($membership->active);
    }

    /** A person who already has a role in the barbershop cannot get a second one (UP-07). */
    public function test_second_role_is_refused(): void
    {
        $this->grant();

        $this->expectException(RoleAlreadyAssignedException::class);

        $this->grant();
    }

    /** Switching a collaborator role off and on changes only its active flag. */
    public function test_collaborator_role_is_switched_off_and_on(): void
    {
        $this->grant();
        $command = new CollaboratorMembershipCommand(self::BARBERSHOP_ID, self::USER_ID);

        new DeactivateCollaboratorMembership($this->memberships)->execute($command);
        $this->assertFalse($this->stored()->active);

        new ActivateCollaboratorMembership($this->memberships)->execute($command);
        $this->assertTrue($this->stored()->active);
        $this->assertSame(Role::Collaborator, $this->stored()->role);
        $this->assertSame(1, $this->memberships->count());
    }

    /** The owner keeps an active membership however their collaborator profile changes (UP-05). */
    public function test_owner_membership_is_never_switched_off(): void
    {
        new AssignBarbershopOwner($this->memberships)->execute(new AssignBarbershopOwnerCommand(self::BARBERSHOP_ID, self::USER_ID));

        new DeactivateCollaboratorMembership($this->memberships)
            ->execute(new CollaboratorMembershipCommand(self::BARBERSHOP_ID, self::USER_ID));

        $this->assertTrue($this->stored()->active);
        $this->assertSame(Role::Owner, $this->stored()->role);
    }

    /** A person without a membership is left as they are. */
    public function test_unknown_membership_is_ignored(): void
    {
        new DeactivateCollaboratorMembership($this->memberships)
            ->execute(new CollaboratorMembershipCommand(self::BARBERSHOP_ID, self::USER_ID));

        $this->assertSame(0, $this->memberships->count());
    }

    /** Grant the collaborator role to the person of the scenario. */
    private function grant(): Membership
    {
        return new GrantCollaboratorMembership($this->memberships)
            ->execute(new CollaboratorMembershipCommand(self::BARBERSHOP_ID, self::USER_ID));
    }

    /** Read the stored membership of the person of the scenario. */
    private function stored(): Membership
    {
        return $this->memberships->findByUserAndBarbershop(new UserId(self::USER_ID), new BarbershopId(self::BARBERSHOP_ID))
            ?? $this->fail('The membership was not stored.');
    }
}
