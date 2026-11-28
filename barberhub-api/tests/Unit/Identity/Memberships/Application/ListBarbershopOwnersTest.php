<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Memberships\Application;

use PHPUnit\Framework\TestCase;
use Src\Identity\Memberships\Application\DTOs\AssignBarbershopOwnerCommand;
use Src\Identity\Memberships\Application\DTOs\CollaboratorMembershipCommand;
use Src\Identity\Memberships\Application\UseCases\AssignBarbershopOwner;
use Src\Identity\Memberships\Application\UseCases\DeactivateCollaboratorMembership;
use Src\Identity\Memberships\Application\UseCases\GrantCollaboratorMembership;
use Src\Identity\Memberships\Application\UseCases\ListBarbershopOwners;
use Tests\Support\Identity\InMemoryMembershipRepository;
use Tests\Support\Identity\InMemoryPlatformOperators;

/** Telling which accounts own a barbershop (CO-02): only an active owner role in that barbershop counts. */
final class ListBarbershopOwnersTest extends TestCase
{
    private const string BARBERSHOP = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d22';

    private const string OTHER_BARBERSHOP = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d23';

    private const string OWNER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    private const string COLLABORATOR = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d45';

    private const string OWNER_ELSEWHERE = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d46';

    private ListBarbershopOwners $listOwners;

    /** An owner and a collaborator of the barbershop, and the owner of another one. */
    protected function setUp(): void
    {
        $memberships = new InMemoryMembershipRepository;
        $assign = new AssignBarbershopOwner($memberships, new InMemoryPlatformOperators);
        $assign->execute(new AssignBarbershopOwnerCommand(self::BARBERSHOP, self::OWNER));
        $assign->execute(new AssignBarbershopOwnerCommand(self::OTHER_BARBERSHOP, self::OWNER_ELSEWHERE));
        new GrantCollaboratorMembership($memberships, new InMemoryPlatformOperators)->execute(new CollaboratorMembershipCommand(self::BARBERSHOP, self::COLLABORATOR));
        $this->listOwners = new ListBarbershopOwners($memberships);
    }

    /** Among several accounts only the owner of the barbershop comes back. */
    public function test_only_the_owner_of_the_barbershop_is_picked(): void
    {
        $owners = $this->listOwners->execute([self::OWNER, self::COLLABORATOR, self::OWNER_ELSEWHERE], self::BARBERSHOP);

        $this->assertSame([self::OWNER], $owners);
    }

    /** The owner of another barbershop is not the owner of this one. */
    public function test_the_owner_of_another_barbershop_does_not_count(): void
    {
        $this->assertSame([], $this->listOwners->execute([self::OWNER_ELSEWHERE], self::BARBERSHOP));
    }

    /** With no accounts there is nothing to read. */
    public function test_no_accounts_give_no_owners(): void
    {
        $this->assertSame([], $this->listOwners->execute([], self::BARBERSHOP));
    }

    /** A collaborator role that was switched off is still not an owner role. */
    public function test_a_deactivated_collaborator_is_not_an_owner(): void
    {
        $memberships = new InMemoryMembershipRepository;
        new GrantCollaboratorMembership($memberships, new InMemoryPlatformOperators)->execute(new CollaboratorMembershipCommand(self::BARBERSHOP, self::COLLABORATOR));
        new DeactivateCollaboratorMembership($memberships)->execute(new CollaboratorMembershipCommand(self::BARBERSHOP, self::COLLABORATOR));

        $this->assertSame([], new ListBarbershopOwners($memberships)->execute([self::COLLABORATOR], self::BARBERSHOP));
    }
}
