<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Collaborators\Application;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Src\Catalog\Collaborators\Application\AttachAccounts;
use Src\Catalog\Collaborators\Application\DTOs\CollaboratorWithAccount;
use Src\Catalog\Collaborators\Application\DTOs\CreateCollaboratorCommand;
use Src\Catalog\Collaborators\Application\DTOs\ReplaceScheduleCommand;
use Src\Catalog\Collaborators\Application\DTOs\ReplaceServicesCommand;
use Src\Catalog\Collaborators\Application\DTOs\ScheduleEntry;
use Src\Catalog\Collaborators\Application\UseCases\CreateCollaboratorWithAccount;
use Src\Catalog\Collaborators\Application\UseCases\CreateOwnCollaboratorProfile;
use Src\Catalog\Collaborators\Application\UseCases\GetCollaborator;
use Src\Catalog\Collaborators\Application\UseCases\ListCollaborators;
use Src\Catalog\Collaborators\Application\UseCases\ListCollaboratorsOfferingService;
use Src\Catalog\Collaborators\Application\UseCases\ReplaceSchedule;
use Src\Catalog\Collaborators\Application\UseCases\ReplaceServices;
use Src\Catalog\Collaborators\Application\UseCases\SetCollaboratorActive;
use Src\Catalog\Collaborators\Domain\Entities\Collaborator;
use Src\Catalog\Collaborators\Domain\Exceptions\BreakOutsideShiftException;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorAlreadyExistsException;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Catalog\Collaborators\Domain\Exceptions\ScheduleEntryException;
use Src\Catalog\Collaborators\Domain\Exceptions\ShiftOutsideOpeningHoursException;
use Src\Catalog\Collaborators\Domain\Exceptions\ShiftsOverlapException;
use Src\Catalog\Collaborators\Domain\Exceptions\UnknownServiceException;
use Src\Catalog\Collaborators\Domain\ValueObjects\CollaboratorId;
use Src\Catalog\OpeningHours\Application\DTOs\AddTimeSlotCommand;
use Src\Catalog\OpeningHours\Application\UseCases\AddTimeSlot;
use Src\Identity\Memberships\Application\DTOs\AssignBarbershopOwnerCommand;
use Src\Identity\Memberships\Application\UseCases\AssignBarbershopOwner;
use Src\Identity\Memberships\Domain\Entities\Membership;
use Src\Identity\Memberships\Domain\ValueObjects\Role;
use Src\Identity\Users\Domain\Exceptions\EmailAlreadyRegisteredException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;
use Tests\Support\Catalog\InMemoryBarbershopSchedule;
use Tests\Support\Catalog\InMemoryBarbershopServices;
use Tests\Support\Catalog\InMemoryCollaboratorRepository;
use Tests\Support\Catalog\InMemoryStaffAccounts;
use Tests\Support\Catalog\InMemoryStaffMemberships;
use Tests\Support\Catalog\InMemoryTimeSlotRepository;
use Tests\Support\Identity\InMemoryMembershipRepository;
use Tests\Support\Identity\InMemoryUserRepository;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Shared\RecordingTransactionRunner;

/** The owner creates collaborators with their services, weekly schedule and breaks (RF-05, CO-01 to CO-03, HO-02, UP-04). */
final class ManageCollaboratorsTest extends TestCase
{
    private const string BARBERSHOP_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d22';

    private const string OWNER_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    private const string HAIRCUT = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0e01';

    private const string KIDS_HAIRCUT = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0e02';

    private InMemoryCollaboratorRepository $collaborators;

    private InMemoryUserRepository $users;

    private InMemoryMembershipRepository $memberships;

    private InMemoryBarbershopServices $services;

    private RecordingTransactionRunner $transaction;

    private AttachAccounts $attach;

    private InMemoryStaffMemberships $staffMemberships;

    private FixedBarbershopContext $context;

    private InMemoryBarbershopSchedule $schedule;

    /** A barbershop that opens Monday from 09:00 to 12:00 and from 13:00 to 18:00, with two services and its owner. */
    protected function setUp(): void
    {
        $this->collaborators = new InMemoryCollaboratorRepository;
        $this->users = new InMemoryUserRepository;
        $this->memberships = new InMemoryMembershipRepository;
        $this->services = new InMemoryBarbershopServices;
        $this->transaction = new RecordingTransactionRunner;
        $this->context = new FixedBarbershopContext(new BarbershopId(self::BARBERSHOP_ID));
        $this->attach = new AttachAccounts(new InMemoryStaffAccounts($this->users));
        $this->staffMemberships = new InMemoryStaffMemberships($this->memberships);

        $slots = new InMemoryTimeSlotRepository;
        $this->schedule = new InMemoryBarbershopSchedule($slots);
        $add = new AddTimeSlot($slots);
        $add->execute(new AddTimeSlotCommand(1, '09:00', '12:00'));
        $add->execute(new AddTimeSlotCommand(1, '13:00', '18:00'));

        $this->services->add(self::HAIRCUT);
        $this->services->add(self::KIDS_HAIRCUT);
        new AssignBarbershopOwner($this->memberships)->execute(new AssignBarbershopOwnerCommand(self::BARBERSHOP_ID, self::OWNER_ID));
    }

    /** The account, the collaborator role and the profile are created together, and the account must change its password. */
    public function test_collaborator_is_created_with_account_role_and_profile(): void
    {
        $created = $this->create('Kevin Solano', 'kevin@barberhub.test');

        $this->assertSame('Kevin Solano', $created->name);
        $this->assertSame('kevin@barberhub.test', $created->email);
        $this->assertTrue($created->collaborator->active);

        $account = $this->users->findByEmail(new Email('kevin@barberhub.test'));
        $this->assertTrue($account?->mustChangePassword);
        $this->assertSame($account->id->value, $created->collaborator->userId->value);

        $membership = $this->membershipOf($created->collaborator->userId->value);
        $this->assertSame(Role::Collaborator, $membership?->role);
        $this->assertTrue($membership->active);
        $this->assertSame(1, $this->transaction->committed());
    }

    /** A temporary password that does not meet SE-07 creates nothing: no account, no role, no profile. */
    public function test_weak_temporary_password_creates_nothing(): void
    {
        try {
            $this->createWithPassword('Kevin Solano', 'kevin@barberhub.test', 'temporal');
            $this->fail('A weak temporary password was accepted.');
        } catch (InvalidValueException) {
            $this->assertSame(0, $this->users->count());
            $this->assertSame(1, $this->memberships->count());
            $this->assertSame(0, $this->collaborators->count());
            $this->assertSame(1, $this->transaction->rolledBack());
        }
    }

    /** An email that already has an account is refused, and linking it is left to the invitation flow (RF-06). */
    public function test_repeated_email_is_refused(): void
    {
        $this->create('Kevin Solano', 'kevin@barberhub.test');

        try {
            $this->create('Otro Kevin', 'KEVIN@barberhub.test');
            $this->fail('A repeated email was accepted.');
        } catch (EmailAlreadyRegisteredException) {
            $this->assertSame(1, $this->users->count());
            $this->assertSame(1, $this->collaborators->count());
        }
    }

    /** When the profile cannot be saved the transaction ends with an error, so the database undoes the account and the role. */
    public function test_a_failure_after_the_account_ends_the_transaction_with_an_error(): void
    {
        $this->collaborators->failOnSave = true;

        $this->expectException(RuntimeException::class);

        try {
            $this->create('Kevin Solano', 'kevin@barberhub.test');
        } finally {
            $this->assertSame(1, $this->transaction->rolledBack());
            $this->assertSame(0, $this->transaction->committed());
        }
    }

    /** The owner gets a profile without a new membership and keeps the owner role (CO-02, UP-07). */
    public function test_owner_profile_keeps_the_owner_role_and_adds_no_membership(): void
    {

        $created = $this->createOwnProfile();

        $this->assertSame(self::OWNER_ID, $created->collaborator->userId->value);
        $this->assertSame(1, $this->memberships->count());
        $this->assertSame(Role::Owner, $this->membershipOf(self::OWNER_ID)?->role);
    }

    /** The owner has one profile, not two. */
    public function test_owner_cannot_create_a_second_profile(): void
    {
        $this->createOwnProfile();

        $this->expectException(CollaboratorAlreadyExistsException::class);

        $this->createOwnProfile();
    }

    /** The collaborators are listed with their account and read one by one. */
    public function test_collaborators_are_listed_and_read(): void
    {
        $kevin = $this->create('Kevin Solano', 'kevin@barberhub.test');
        $this->create('Ana Mora', 'ana@barberhub.test');

        $listed = new ListCollaborators($this->collaborators, $this->attach)->execute();
        $read = new GetCollaborator($this->collaborators, $this->attach)->execute($kevin->collaborator->id->value);

        $this->assertSame(['Kevin Solano', 'Ana Mora'], array_map(fn (CollaboratorWithAccount $item): string => $item->name, $listed));
        $this->assertSame('kevin@barberhub.test', $read->email);
    }

    /** A collaborator that does not exist cannot be read. */
    public function test_unknown_collaborator_is_not_found(): void
    {
        $this->expectException(CollaboratorNotFoundException::class);

        new GetCollaborator($this->collaborators, $this->attach)->execute('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0fff');
    }

    /** The services are replaced as a whole and each one must exist in the barbershop (CO-01). */
    public function test_services_are_replaced_and_must_exist(): void
    {
        $kevin = $this->create('Kevin Solano', 'kevin@barberhub.test');
        $replaceServices = new ReplaceServices($this->collaborators, $this->services, $this->attach);

        $updated = $replaceServices->execute(new ReplaceServicesCommand($kevin->collaborator->id->value, [self::HAIRCUT]));
        $this->assertSame([self::HAIRCUT], array_map(fn ($service): string => $service->value, $updated->collaborator->services));

        try {
            $replaceServices->execute(new ReplaceServicesCommand($kevin->collaborator->id->value, [self::HAIRCUT, '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0eff']));
            $this->fail('A service of another barbershop was accepted.');
        } catch (UnknownServiceException $exception) {
            $this->assertSame(1, $exception->index());
            $this->assertCount(1, $this->stored($kevin->collaborator->id)->services);
        }
    }

    /** Kevin, without the kids haircut enabled, does not show up for it; whoever has it enabled does (CO-01). */
    public function test_a_collaborator_without_the_service_is_not_listed_for_it(): void
    {
        $kevin = $this->create('Kevin Solano', 'kevin@barberhub.test');
        $ana = $this->create('Ana Mora', 'ana@barberhub.test');
        $replaceServices = new ReplaceServices($this->collaborators, $this->services, $this->attach);
        $replaceServices->execute(new ReplaceServicesCommand($kevin->collaborator->id->value, [self::HAIRCUT]));
        $replaceServices->execute(new ReplaceServicesCommand($ana->collaborator->id->value, [self::HAIRCUT, self::KIDS_HAIRCUT]));

        $offering = new ListCollaboratorsOfferingService($this->collaborators);

        $this->assertSame([$ana->collaborator->id->value], $this->ids($offering->execute(self::KIDS_HAIRCUT)));
        $this->assertSame([$kevin->collaborator->id->value, $ana->collaborator->id->value], $this->ids($offering->execute(self::HAIRCUT)));
    }

    /** A deactivated collaborator is not offered for any service until they are activated again. */
    public function test_a_deactivated_collaborator_is_not_listed_for_the_service(): void
    {
        $ana = $this->create('Ana Mora', 'ana@barberhub.test');
        new ReplaceServices($this->collaborators, $this->services, $this->attach)
            ->execute(new ReplaceServicesCommand($ana->collaborator->id->value, [self::KIDS_HAIRCUT]));
        $offering = new ListCollaboratorsOfferingService($this->collaborators);

        $this->setActive($ana->collaborator->id->value, false);
        $this->assertSame([], $offering->execute(self::KIDS_HAIRCUT));

        $this->setActive($ana->collaborator->id->value, true);
        $this->assertCount(1, $offering->execute(self::KIDS_HAIRCUT));
    }

    /** A schedule inside the opening hours is saved with its breaks, and one that touches two openings is accepted. */
    public function test_schedule_inside_the_opening_hours_is_saved(): void
    {
        $kevin = $this->create('Kevin Solano', 'kevin@barberhub.test');

        $updated = $this->replaceSchedule(
            $kevin->collaborator->id->value,
            [new ScheduleEntry(1, '09:00', '12:00'), new ScheduleEntry(1, '13:00', '17:00')],
            [new ScheduleEntry(1, '15:00', '15:30')],
        );

        $this->assertCount(2, $updated->collaborator->schedule->shifts);
        $this->assertCount(1, $updated->collaborator->schedule->breaks);
        $this->assertEquals($updated->collaborator->schedule, $this->stored($kevin->collaborator->id)->schedule);
    }

    /** A shift that starts before the opening of the barbershop is refused and the old schedule stays (HO-02). */
    public function test_shift_that_starts_before_the_opening_is_refused(): void
    {
        $kevin = $this->create('Kevin Solano', 'kevin@barberhub.test');
        $this->replaceSchedule($kevin->collaborator->id->value, [new ScheduleEntry(1, '09:00', '12:00')], []);

        try {
            $this->replaceSchedule($kevin->collaborator->id->value, [new ScheduleEntry(1, '09:00', '12:00'), new ScheduleEntry(1, '08:30', '09:00')], []);
            $this->fail('A shift before the opening was accepted.');
        } catch (ShiftOutsideOpeningHoursException $exception) {
            $this->assertSame(1, $exception->index());
            $this->assertCount(1, $this->stored($kevin->collaborator->id)->schedule->shifts);
        }
    }

    /** A shift on a day the barbershop does not open, or across the lunch gap of its openings, is refused (HO-02). */
    public function test_shift_on_a_closed_day_or_across_a_gap_is_refused(): void
    {
        $kevin = $this->create('Kevin Solano', 'kevin@barberhub.test');

        foreach ([new ScheduleEntry(2, '09:00', '10:00'), new ScheduleEntry(1, '11:00', '14:00')] as $shift) {
            try {
                $this->replaceSchedule($kevin->collaborator->id->value, [$shift], []);
                $this->fail('A shift outside the opening hours was accepted.');
            } catch (ShiftOutsideOpeningHoursException $exception) {
                $this->assertSame(0, $exception->index());
            }
        }
    }

    /** A break outside the shift and overlapping shifts are refused with the entry that caused it. */
    public function test_break_outside_the_shift_and_overlapping_shifts_are_refused(): void
    {
        $kevin = $this->create('Kevin Solano', 'kevin@barberhub.test');
        $id = $kevin->collaborator->id->value;

        $this->assertRefused(BreakOutsideShiftException::class, $id, [new ScheduleEntry(1, '09:00', '12:00')], [new ScheduleEntry(1, '11:30', '12:30')]);
        $this->assertRefused(ShiftsOverlapException::class, $id, [new ScheduleEntry(1, '09:00', '11:00'), new ScheduleEntry(1, '10:00', '12:00')], []);
    }

    /** Sending no entries leaves the collaborator without working hours. */
    public function test_empty_schedule_clears_the_working_hours(): void
    {
        $kevin = $this->create('Kevin Solano', 'kevin@barberhub.test');
        $this->replaceSchedule($kevin->collaborator->id->value, [new ScheduleEntry(1, '09:00', '12:00')], []);

        $updated = $this->replaceSchedule($kevin->collaborator->id->value, [], []);

        $this->assertSame([], $updated->collaborator->schedule->shifts);
    }

    /** Deactivating a collaborator switches off their role, and activating brings it back (CO-03). */
    public function test_deactivating_switches_off_the_collaborator_role(): void
    {
        $kevin = $this->create('Kevin Solano', 'kevin@barberhub.test');

        $off = $this->setActive($kevin->collaborator->id->value, false);
        $this->assertFalse($off->collaborator->active);
        $this->assertFalse($this->membershipOf($kevin->collaborator->userId->value)?->active);

        $on = $this->setActive($kevin->collaborator->id->value, true);
        $this->assertTrue($on->collaborator->active);
        $this->assertTrue($this->membershipOf($kevin->collaborator->userId->value)?->active);
    }

    /** The owner with a profile keeps an active owner membership when the profile is deactivated (UP-05). */
    public function test_deactivating_the_owner_profile_keeps_the_owner_membership(): void
    {
        $profile = $this->createOwnProfile();

        $this->setActive($profile->collaborator->id->value, false);

        $membership = $this->membershipOf(self::OWNER_ID);
        $this->assertSame(Role::Owner, $membership?->role);
        $this->assertTrue($membership->active);
    }

    /** Create a collaborator with a valid temporary password. */
    private function create(string $name, string $email): CollaboratorWithAccount
    {
        return $this->createWithPassword($name, $email, 'Temporal#1');
    }

    /** Create a collaborator with the given temporary password. */
    private function createWithPassword(string $name, string $email, string $password): CollaboratorWithAccount
    {
        return new CreateCollaboratorWithAccount(
            $this->collaborators,
            new InMemoryStaffAccounts($this->users),
            $this->staffMemberships,
            $this->context,
            $this->transaction,
            $this->attach,
        )->execute(new CreateCollaboratorCommand($name, $email, $password));
    }

    /** Create the profile of the owner. */
    private function createOwnProfile(): CollaboratorWithAccount
    {
        return new CreateOwnCollaboratorProfile($this->collaborators, $this->attach)->execute(self::OWNER_ID);
    }

    /**
     * Replace the schedule of a collaborator.
     *
     * @param  list<ScheduleEntry>  $shifts
     * @param  list<ScheduleEntry>  $breaks
     */
    private function replaceSchedule(string $id, array $shifts, array $breaks): CollaboratorWithAccount
    {
        return new ReplaceSchedule($this->collaborators, $this->schedule, $this->attach)
            ->execute(new ReplaceScheduleCommand($id, $shifts, $breaks));
    }

    /**
     * Check that a schedule is refused with the exception.
     *
     * @param  class-string<ScheduleEntryException>  $exception
     * @param  list<ScheduleEntry>  $shifts
     * @param  list<ScheduleEntry>  $breaks
     */
    private function assertRefused(string $exception, string $id, array $shifts, array $breaks): void
    {
        try {
            $this->replaceSchedule($id, $shifts, $breaks);
            $this->fail('The schedule was accepted.');
        } catch (ScheduleEntryException $caught) {
            $this->assertInstanceOf($exception, $caught);
        }
    }

    /** Activate or deactivate a collaborator. */
    private function setActive(string $id, bool $active): CollaboratorWithAccount
    {
        return new SetCollaboratorActive($this->collaborators, $this->staffMemberships, $this->context, $this->transaction, $this->attach)
            ->execute($id, $active);
    }

    /** Read a stored collaborator. */
    private function stored(CollaboratorId $id): Collaborator
    {
        return $this->collaborators->find($id) ?? $this->fail('The collaborator was not stored.');
    }

    /** Read the membership of a person in the barbershop. */
    private function membershipOf(string $userId): ?Membership
    {
        return $this->memberships->findByUserAndBarbershop(new UserId($userId), new BarbershopId(self::BARBERSHOP_ID));
    }

    /**
     * Identifiers of the collaborators.
     *
     * @param  list<Collaborator>  $collaborators
     * @return list<string>
     */
    private function ids(array $collaborators): array
    {
        return array_map(fn (Collaborator $collaborator): string => $collaborator->id->value, $collaborators);
    }
}
