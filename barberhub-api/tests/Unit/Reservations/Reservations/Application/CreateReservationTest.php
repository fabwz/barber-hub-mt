<?php

declare(strict_types=1);

namespace Tests\Unit\Reservations\Reservations\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Identity\Memberships\Application\UseCases\EnsureCustomerMembership;
use Src\Identity\Memberships\Domain\Entities\Membership;
use Src\Identity\Memberships\Domain\Exceptions\StaffCannotBeCustomerException;
use Src\Identity\Memberships\Domain\ValueObjects\Role;
use Src\Reservations\Reservations\Application\Contracts\AnyAvailableCollaborators;
use Src\Reservations\Reservations\Application\Contracts\BookableServices;
use Src\Reservations\Reservations\Application\Contracts\CancellationDeadlines;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorLock;
use Src\Reservations\Reservations\Application\Contracts\CustomerMemberships;
use Src\Reservations\Reservations\Application\Contracts\FreeSlots;
use Src\Reservations\Reservations\Application\Contracts\PaymentRequirement;
use Src\Reservations\Reservations\Application\DTOs\BookableService;
use Src\Reservations\Reservations\Application\DTOs\CreateReservationCommand;
use Src\Reservations\Reservations\Application\UseCases\CreateReservation;
use Src\Reservations\Reservations\Application\UseCases\ExpireOverdueReservations;
use Src\Reservations\Reservations\Domain\Events\ReservationConfirmed;
use Src\Reservations\Reservations\Domain\Events\ReservationCreated;
use Src\Reservations\Reservations\Domain\Events\ReservationExpired;
use Src\Reservations\Reservations\Domain\Events\SpaceReleased;
use Src\Reservations\Reservations\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Reservations\Reservations\Domain\Exceptions\OverlappingReservationException;
use Src\Reservations\Reservations\Domain\Exceptions\ServiceNotBookableException;
use Src\Reservations\Reservations\Domain\Exceptions\SlotUnavailableException;
use Src\Reservations\Reservations\Domain\Exceptions\VoucherNotRequiredException;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Domain\ValueObjects\SlotAlternative;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Shared\Domain\ValueObjects\UserId;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\InsufficientBalanceException;
use Tests\Support\Identity\InMemoryMembershipRepository;
use Tests\Support\Reservations\InMemoryReservationRepository;
use Tests\Support\Reservations\RecordingReservationExpiries;
use Tests\Support\Reservations\RecordingReservationVouchers;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Shared\RecordingDomainEvents;
use Tests\Support\Shared\RecordingTransactionRunner;

/** Daniela's space of 10:00 is booked by Ana in a barbershop with no payment policy yet (RF-11, RE-01, RE-02, RE-06, RE-07, SV-03, UP-09). */
final class CreateReservationTest extends TestCase
{
    private const string BARBERSHOP_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d22';

    private const string ANA_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    private const string SERVICE_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d33';

    private const string DANIELA_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d55';

    private const string KEVIN_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d66';

    /** The smallest identifier of the three, so the order of the locks differs from the order of the candidates. */
    private const string LUIS_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d11';

    private InMemoryReservationRepository $reservations;

    private InMemoryMembershipRepository $memberships;

    private RecordingTransactionRunner $transaction;

    private RecordingReservationVouchers $vouchers;

    private RecordingDomainEvents $events;

    private RecordingReservationExpiries $expiries;

    /** @var list<string> What happened, in order. */
    private array $calls = [];

    /** Whether the calculation of the availability lists the requested space as free. */
    private bool $free = true;

    /** @var list<SlotAlternative> What the availability offers instead. */
    private array $alternatives = [];

    /** @var list<string> The collaborators "any available" may assign, in the order the availability gives them. */
    private array $candidates = [self::LUIS_ID, self::DANIELA_ID, self::KEVIN_ID];

    /** The collaborator the assignment of DI-04 picks, or none when nobody is free. */
    private ?string $assigned = self::DANIELA_ID;

    /** @var list<string> The identifiers locked, in order. */
    private array $locked = [];

    private bool $serviceExists = true;

    /** The free-cancellation deadline the barbershop has now. */
    private int $deadlineHours = 12;

    private bool $collaboratorExists = true;

    /** Whether the barbershop charges before it confirms. */
    private bool $paymentRequired = false;

    private CreateReservation $createReservation;

    /** Wire the use case to in-memory ports that log their calls. */
    protected function setUp(): void
    {
        $this->reservations = new InMemoryReservationRepository;
        $this->memberships = new InMemoryMembershipRepository;
        $this->transaction = new RecordingTransactionRunner;
        $this->vouchers = new RecordingReservationVouchers;
        $this->events = new RecordingDomainEvents;
        $this->expiries = new RecordingReservationExpiries;
        $test = $this;

        $services = new class($test) implements BookableServices
        {
            public function __construct(private readonly CreateReservationTest $test) {}

            public function find(string $serviceId): ?BookableService
            {
                return $this->test->serviceExists() ? new BookableService(Money::of(8500), Duration::ofMinutes(45)) : null;
            }
        };
        $deadlines = new class($test) implements CancellationDeadlines
        {
            public function __construct(private readonly CreateReservationTest $test) {}

            public function current(): int
            {
                return $this->test->deadlineHours();
            }
        };
        $lock = new class($test) implements CollaboratorLock
        {
            public function __construct(private readonly CreateReservationTest $test) {}

            public function lock(string $collaboratorId): void
            {
                $this->test->log('lock');
                $this->test->locked($collaboratorId);
                $this->test->collaboratorExists() || throw CollaboratorNotFoundException::create();
            }
        };
        $slots = new class($test) implements FreeSlots
        {
            public function __construct(private readonly CreateReservationTest $test) {}

            public function isFree(string $serviceId, string $collaboratorId, DateTimeImmutable $startsAt, DateTimeImmutable $now, ?string $ignoringReservationId = null): bool
            {
                $this->test->log('check');

                return $this->test->isFree();
            }

            public function alternatives(string $serviceId, string $collaboratorId, DateTimeImmutable $requested, DateTimeImmutable $now, ?string $ignoringReservationId = null): array
            {
                $this->test->log('alternatives');

                return $this->test->alternatives();
            }
        };
        $anyAvailable = new class($test) implements AnyAvailableCollaborators
        {
            public function __construct(private readonly CreateReservationTest $test) {}

            public function candidates(string $serviceId, DateTimeImmutable $startsAt): array
            {
                $this->test->log('candidates');

                return $this->test->candidates();
            }

            public function assign(string $serviceId, DateTimeImmutable $startsAt, DateTimeImmutable $now): ?string
            {
                $this->test->log('assign');

                return $this->test->assigned();
            }

            public function alternatives(string $serviceId, DateTimeImmutable $requested, DateTimeImmutable $now): array
            {
                $this->test->log('any-alternatives');

                return $this->test->alternatives();
            }
        };
        $customers = new class($test, new EnsureCustomerMembership($this->memberships)) implements CustomerMemberships
        {
            public function __construct(private readonly CreateReservationTest $test, private readonly EnsureCustomerMembership $ensure) {}

            public function ensure(string $userId): string
            {
                $this->test->log('membership');

                return $this->ensure->execute($userId, '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d22');
            }
        };

        $payment = new class($this) implements PaymentRequirement
        {
            public function __construct(private readonly CreateReservationTest $test) {}

            public function isRequired(): bool
            {
                return $this->test->paymentRequired();
            }
        };

        $this->createReservation = new CreateReservation($this->reservations, $services, $deadlines, $lock, $slots, $anyAvailable, $customers, $payment, new ExpireOverdueReservations($this->reservations, $this->events, new FixedBarbershopContext(new BarbershopId(self::BARBERSHOP_ID))), $this->transaction, $this->vouchers, $this->expiries, $this->events, new FixedBarbershopContext(new BarbershopId(self::BARBERSHOP_ID)));
    }

    /** The reservation is confirmed at once with the copies of the service, and its history has creation and confirmation (RE-07, SV-03, RE-09). */
    public function test_the_reservation_is_confirmed_with_the_copies_of_the_service(): void
    {
        $reservation = $this->createReservation->execute($this->command(), self::at('2030-03-01 08:00'));

        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame(8500, $reservation->price->colones);
        $this->assertSame(45, $reservation->duration->minutes);
        $this->assertSame(12, $reservation->cancellationDeadlineHours);
        $this->assertSame(self::DANIELA_ID, $reservation->collaboratorId->value);
        $this->assertEquals(self::at('2030-03-05 10:45'), $reservation->interval->end);
        $this->assertSame([2], $this->reservations->entriesPerSave);
        $this->assertNotNull($this->reservations->find($reservation->id));
    }

    /** A barbershop that charges leaves the reservation temporary, with 10 minutes to pay, and it is not confirmed (RE-05, PA-06). */
    public function test_a_barbershop_that_charges_leaves_the_reservation_temporary_with_a_deadline(): void
    {
        $this->paymentRequired = true;

        $reservation = $this->createReservation->execute($this->command(), self::at('2030-03-01 08:00'));

        $this->assertSame(ReservationStatus::Temporary, $reservation->status);
        $this->assertEquals(self::at('2030-03-01 08:10'), $reservation->expiresAt);
        $this->assertSame([1], $this->reservations->entriesPerSave);
    }

    /** A temporary reservation of someone else whose window closed is expired before the space is checked, and it frees the space (RE-05, DI-05). */
    public function test_an_overdue_temporary_reservation_is_expired_before_the_check(): void
    {
        $this->paymentRequired = true;
        $stale = $this->createReservation->execute($this->command(), self::at('2030-03-01 08:00'));
        $this->paymentRequired = false;

        $fresh = $this->createReservation->execute($this->command(), self::at('2030-03-01 08:10'));

        $this->assertSame(ReservationStatus::Expired, $stale->status);
        $this->assertTrue($this->reservations->history($stale->id)[1]->author->isSystem());
        $this->assertSame(ReservationStatus::Confirmed, $fresh->status);
    }

    /** A temporary reservation leaves a task for the instant its payment window closes, and the reservation that is confirmed at once leaves none (RE-05, RE-07). */
    public function test_only_a_temporary_reservation_schedules_its_expiry(): void
    {
        $confirmed = $this->createReservation->execute($this->command(self::at('2030-03-06 10:00')), self::at('2030-03-01 07:00'));
        $this->assertSame([], $this->expiries->scheduled());
        $this->assertSame(ReservationStatus::Confirmed, $confirmed->status);

        $this->paymentRequired = true;
        $temporary = $this->createReservation->execute($this->command(), self::at('2030-03-01 08:00'));

        $scheduled = $this->expiries->scheduled();
        $this->assertCount(1, $scheduled);
        $this->assertSame($temporary->id->value, $scheduled[0][0]);
        $this->assertEquals(self::at('2030-03-01 08:10'), $scheduled[0][1]);
    }

    /** The temporary reservation that expires on the way announces its space as released, once, for the waitlist (RE-05, RF-31). */
    public function test_the_expired_reservation_announces_its_space(): void
    {
        $this->paymentRequired = true;
        $stale = $this->createReservation->execute($this->command(), self::at('2030-03-01 08:00'));
        $this->paymentRequired = false;
        $this->assertCount(0, $this->events->of(SpaceReleased::class));

        $this->createReservation->execute($this->command(), self::at('2030-03-01 08:10'));
        $this->createReservation->execute($this->command(self::at('2030-03-06 10:00')), self::at('2030-03-01 08:11'));

        $released = $this->events->of(SpaceReleased::class);
        $this->assertCount(1, $released);
        $this->assertSame(self::BARBERSHOP_ID, $released[0]->barbershopId);
        $this->assertSame($stale->id->value, $released[0]->reservationId);
        $this->assertSame(self::DANIELA_ID, $released[0]->collaboratorId);
        $this->assertEquals($stale->interval->start, $released[0]->startsAt);
        $this->assertEquals($stale->blockedUntil, $released[0]->endsAt);

        $expired = $this->events->of(ReservationExpired::class);
        $this->assertCount(1, $expired);
        $this->assertSame(self::BARBERSHOP_ID, $expired[0]->barbershopId);
        $this->assertSame($stale->id->value, $expired[0]->reservationId);
        $this->assertSame(self::DANIELA_ID, $expired[0]->collaboratorId);
        $this->assertEquals($stale->interval->end, $expired[0]->endsAt);
    }

    /** A temporary reservation announces its creation for the live agenda, with its collaborator and interval, and a confirmed one does not (RF-36, RE-05). */
    public function test_a_temporary_reservation_announces_its_creation(): void
    {
        $this->paymentRequired = true;
        $temporary = $this->createReservation->execute($this->command(), self::at('2030-03-01 08:00'));

        $created = $this->events->of(ReservationCreated::class);
        $this->assertCount(1, $created);
        $this->assertSame(self::BARBERSHOP_ID, $created[0]->barbershopId);
        $this->assertSame($temporary->id->value, $created[0]->reservationId);
        $this->assertSame(self::DANIELA_ID, $created[0]->collaboratorId);
        $this->assertEquals($temporary->interval->start, $created[0]->startsAt);

        $this->paymentRequired = false;
        $this->createReservation->execute($this->command(self::at('2030-03-06 10:00')), self::at('2030-03-01 08:01'));

        $this->assertCount(1, $this->events->of(ReservationCreated::class));
    }

    /** A reservation confirmed on the spot announces it, with its customer, service and interval, and a temporary one does not (RE-07, LE-06). */
    public function test_a_confirmed_reservation_announces_it_and_a_temporary_one_does_not(): void
    {
        $this->paymentRequired = true;
        $this->createReservation->execute($this->command(), self::at('2030-03-01 08:00'));
        $this->assertCount(0, $this->events->of(ReservationConfirmed::class));

        $this->paymentRequired = false;
        $reservation = $this->createReservation->execute($this->command(self::at('2030-03-06 10:00')), self::at('2030-03-01 08:01'));

        $announced = $this->events->of(ReservationConfirmed::class);
        $this->assertCount(1, $announced);
        $this->assertSame(self::BARBERSHOP_ID, $announced[0]->barbershopId);
        $this->assertSame($reservation->id->value, $announced[0]->reservationId);
        $this->assertSame($reservation->customerMembershipId->value, $announced[0]->customerMembershipId);
        $this->assertSame(self::SERVICE_ID, $announced[0]->serviceId);
        $this->assertEquals(self::at('2030-03-06 10:00'), $announced[0]->startsAt);
        $this->assertEquals(self::at('2030-03-06 10:45'), $announced[0]->endsAt);
    }

    /** The waitlist names the identifier of the reservation and the limit of its payment, which replaces the 10 minutes (LE-04, RE-05). */
    public function test_the_waitlist_gives_the_identifier_and_the_payment_limit(): void
    {
        $this->paymentRequired = true;
        $id = '0192f3a0-0000-7000-8000-000000000001';

        $reservation = $this->createReservation->execute(
            new CreateReservationCommand(self::ANA_ID, self::SERVICE_ID, self::DANIELA_ID, self::at('2030-03-05 10:00'), false, $id, self::at('2030-03-01 08:15')),
            self::at('2030-03-01 08:00'),
        );

        $this->assertSame($id, $reservation->id->value);
        $this->assertEquals(self::at('2030-03-01 08:15'), $reservation->expiresAt);
        $this->assertEquals(self::at('2030-03-01 08:15'), $this->expiries->scheduled()[0][1]);
    }

    /** The collaborator is locked before the availability is checked again, and the membership comes after the check (RE-01, UP-09). */
    public function test_it_locks_then_revalidates_then_creates_the_membership(): void
    {
        $this->createReservation->execute($this->command(), self::at('2030-03-01 08:00'));

        $this->assertSame(['lock', 'check', 'membership'], $this->calls);
        $this->assertSame(1, $this->transaction->runs());
        $this->assertSame(1, $this->transaction->committed());
    }

    /** The first reservation of a person leaves them as a customer of the barbershop, and the reservation is theirs (UP-09, CL-05). */
    public function test_the_first_reservation_creates_the_customer_membership(): void
    {
        $reservation = $this->createReservation->execute($this->command(), self::at('2030-03-01 08:00'));

        $membership = $this->memberships->findByUserAndBarbershop(new UserId(self::ANA_ID), new BarbershopId(self::BARBERSHOP_ID));
        $this->assertNotNull($membership);
        $this->assertSame(Role::Customer, $membership->role);
        $this->assertSame($membership->id->value, $reservation->customerMembershipId->value);
    }

    /** Without a collaborator the candidates are locked in the order of their identifiers, then the assignment is asked, and the reservation is the assigned one's (DI-04, RE-01). */
    public function test_any_available_locks_every_candidate_in_a_fixed_order_and_books_the_assigned_one(): void
    {
        $reservation = $this->createReservation->execute($this->anyCommand(), self::at('2030-03-01 08:00'));

        $this->assertSame([self::LUIS_ID, self::DANIELA_ID, self::KEVIN_ID], $this->locked);
        $this->assertSame(['candidates', 'lock', 'lock', 'lock', 'assign', 'membership'], $this->calls);
        $this->assertSame(self::DANIELA_ID, $reservation->collaboratorId->value);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
    }

    /** The order of the locks does not depend on the order the candidates arrive in. */
    public function test_any_available_sorts_the_locks_by_identifier(): void
    {
        $this->candidates = [self::KEVIN_ID, self::LUIS_ID, self::DANIELA_ID];
        $this->assigned = self::KEVIN_ID;

        $reservation = $this->createReservation->execute($this->anyCommand(), self::at('2030-03-01 08:00'));

        $this->assertSame([self::LUIS_ID, self::DANIELA_ID, self::KEVIN_ID], $this->locked);
        $this->assertSame(self::KEVIN_ID, $reservation->collaboratorId->value);
    }

    /** If nobody is free the space is taken: the alternatives are those of any collaborator and nothing is left behind (RE-02). */
    public function test_any_available_with_nobody_free_is_refused_with_alternatives_of_any_collaborator(): void
    {
        $this->assigned = null;
        $this->alternatives = [new SlotAlternative(new CollaboratorId(self::KEVIN_ID), TimeInterval::between(self::at('2030-03-05 10:15'), self::at('2030-03-05 11:00')))];

        try {
            $this->createReservation->execute($this->anyCommand(), self::at('2030-03-01 08:00'));
            $this->fail('A taken space was booked.');
        } catch (SlotUnavailableException $exception) {
            $this->assertSame('espacio-no-disponible', $exception->rule());
            $this->assertSame(self::KEVIN_ID, $exception->problemMembers()['alternativas'][0]['barbero']);
        }

        $this->assertSame(['candidates', 'lock', 'lock', 'lock', 'assign', 'any-alternatives'], $this->calls);
        $this->assertSame(0, $this->memberships->count());
        $this->assertSame(0, $this->reservations->count());
        $this->assertSame(1, $this->transaction->rolledBack());
    }

    /** Nobody who could attend means nobody to lock and no space: the same refusal. */
    public function test_any_available_without_candidates_is_refused(): void
    {
        $this->candidates = [];
        $this->assigned = null;

        $this->expectException(SlotUnavailableException::class);

        try {
            $this->createReservation->execute($this->anyCommand(), self::at('2030-03-01 08:00'));
        } finally {
            $this->assertSame([], $this->locked);
        }
    }

    /** With a collaborator named nothing of the assignment is used. */
    public function test_a_named_collaborator_never_asks_for_the_assignment(): void
    {
        $this->createReservation->execute($this->command(), self::at('2030-03-01 08:00'));

        $this->assertNotContains('assign', $this->calls);
        $this->assertNotContains('candidates', $this->calls);
        $this->assertSame([self::DANIELA_ID], $this->locked);
    }

    /** A space that is not free is refused with up to the alternatives of the availability, before any membership or reservation exists (RE-02). */
    public function test_a_space_not_free_is_refused_with_alternatives(): void
    {
        $this->free = false;
        $this->alternatives = [new SlotAlternative(new CollaboratorId(self::DANIELA_ID), TimeInterval::between(self::at('2030-03-05 10:15'), self::at('2030-03-05 11:00')))];

        try {
            $this->createReservation->execute($this->command(), self::at('2030-03-01 08:00'));
            $this->fail('A taken space was booked.');
        } catch (SlotUnavailableException $exception) {
            $this->assertSame('espacio-no-disponible', $exception->rule());
            $this->assertCount(1, $exception->alternatives());
            $this->assertSame(
                [['barbero' => self::DANIELA_ID, 'inicio' => '2030-03-05T10:15:00-06:00', 'fin' => '2030-03-05T11:00:00-06:00']],
                $exception->problemMembers()['alternativas'],
            );
        }

        $this->assertSame(['lock', 'check', 'alternatives'], $this->calls);
        $this->assertSame(0, $this->memberships->count());
        $this->assertSame(0, $this->reservations->count());
        $this->assertSame(1, $this->transaction->rolledBack());
    }

    /** The exclusion of the database is the second barrier: its violation also answers with the alternatives. */
    public function test_a_collision_of_the_database_is_refused_with_alternatives(): void
    {
        $this->reservations->collideOnSave = true;
        $this->alternatives = [new SlotAlternative(new CollaboratorId(self::DANIELA_ID), TimeInterval::between(self::at('2030-03-05 11:00'), self::at('2030-03-05 11:45')))];

        try {
            $this->createReservation->execute($this->command(), self::at('2030-03-01 08:00'));
            $this->fail('A collision was booked.');
        } catch (SlotUnavailableException $exception) {
            $this->assertCount(1, $exception->alternatives());
        }

        $this->assertSame(1, $this->transaction->rolledBack());
        $this->assertSame(0, $this->reservations->count());
    }

    /** A customer with a confirmed reservation at that time cannot book another (RE-06). */
    public function test_a_customer_cannot_book_two_overlapping_spaces(): void
    {
        $this->createReservation->execute($this->command(), self::at('2030-03-01 08:00'));

        $this->expectException(OverlappingReservationException::class);

        try {
            $this->createReservation->execute($this->command(self::at('2030-03-05 10:30')), self::at('2030-03-01 08:00'));
        } finally {
            $this->assertSame(1, $this->reservations->count());
        }
    }

    /** Paying with a voucher in a barbershop that charges confirms at once, with no deposit, and sets one session aside for the appointment (RF-28, BO-04, RE-07). */
    public function test_a_voucher_confirms_the_reservation_and_sets_a_session_aside(): void
    {
        $this->paymentRequired = true;

        $reservation = $this->createReservation->execute($this->command(withVoucher: true), self::at('2030-03-01 08:00'));

        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertNull($reservation->expiresAt);
        $this->assertSame(RecordingReservationVouchers::VOUCHER, $reservation->purchasedVoucherId?->value);
        $this->assertSame(['hold'], $this->vouchers->operations());
        $this->assertSame($reservation->id->value, $this->vouchers->calls[0][2]);
        $this->assertEquals([self::SERVICE_ID, self::at('2030-03-05 10:00')], $this->vouchers->holds[0]);
        $this->assertSame(RecordingReservationVouchers::VOUCHER, $this->reservations->find($reservation->id)?->purchasedVoucherId?->value);
    }

    /** A reservation that does not ask for a voucher never touches one (RF-28). */
    public function test_a_reservation_without_voucher_uses_no_session(): void
    {
        $this->paymentRequired = true;

        $reservation = $this->createReservation->execute($this->command(), self::at('2030-03-01 08:00'));

        $this->assertSame([], $this->vouchers->calls);
        $this->assertNull($reservation->purchasedVoucherId);
        $this->assertSame(ReservationStatus::Temporary, $reservation->status);
    }

    /** With no session to take the transaction is rolled back and neither the reservation nor the membership remain (BO-03, RF-11). */
    public function test_a_refused_voucher_leaves_nothing(): void
    {
        $this->paymentRequired = true;
        $this->vouchers->refuse = InsufficientBalanceException::create();

        try {
            $this->createReservation->execute($this->command(withVoucher: true), self::at('2030-03-01 08:00'));
            $this->fail('The reservation was created without a session.');
        } catch (InsufficientBalanceException) {
            $this->assertSame(1, $this->transaction->rolledBack());
            $this->assertSame(0, $this->transaction->committed());
        }
    }

    /** Where the barbershop does not charge there is no payment to replace: it is refused on the field and no session is used (RF-28). */
    public function test_a_voucher_is_refused_where_the_barbershop_does_not_charge(): void
    {
        try {
            $this->createReservation->execute($this->command(withVoucher: true), self::at('2030-03-01 08:00'));
            $this->fail('The voucher was accepted in a barbershop that does not charge.');
        } catch (VoucherNotRequiredException $exception) {
            $this->assertSame('con_bono', $exception->field());
            $this->assertSame([], $this->vouchers->calls);
            $this->assertSame(0, $this->reservations->count());
            $this->assertSame(0, $this->transaction->runs());
        }
    }

    /** An owner or a collaborator of the barbershop is refused and nothing is stored (UP-07). */
    public function test_staff_of_the_barbershop_is_refused(): void
    {
        $this->memberships->save(Membership::create($this->memberships->nextId(), new UserId(self::ANA_ID), new BarbershopId(self::BARBERSHOP_ID), Role::Collaborator));

        $this->expectException(StaffCannotBeCustomerException::class);

        try {
            $this->createReservation->execute($this->command(), self::at('2030-03-01 08:00'));
        } finally {
            $this->assertSame(0, $this->reservations->count());
        }
    }

    /** A service that cannot be booked stops the request before anything is locked or created. */
    public function test_an_unknown_service_is_not_found(): void
    {
        $this->serviceExists = false;

        try {
            $this->createReservation->execute($this->command(), self::at('2030-03-01 08:00'));
            $this->fail('A missing service was booked.');
        } catch (ServiceNotBookableException) {
            $this->assertSame([], $this->calls);
            $this->assertSame(0, $this->transaction->runs());
            $this->assertSame(0, $this->memberships->count());
        }
    }

    /** A collaborator that is not in the barbershop is not found and leaves no membership. */
    public function test_an_unknown_collaborator_is_not_found(): void
    {
        $this->collaboratorExists = false;

        try {
            $this->createReservation->execute($this->command(), self::at('2030-03-01 08:00'));
            $this->fail('A missing collaborator was booked.');
        } catch (CollaboratorNotFoundException) {
            $this->assertSame(['lock'], $this->calls);
            $this->assertSame(0, $this->memberships->count());
        }
    }

    /** @internal Used by the ports of the test. */
    public function log(string $call): void
    {
        $this->calls[] = $call;
    }

    /** @internal Used by the ports of the test. */
    public function paymentRequired(): bool
    {
        return $this->paymentRequired;
    }

    public function isFree(): bool
    {
        return $this->free;
    }

    /**
     * @internal Used by the ports of the test.
     *
     * @return list<SlotAlternative>
     */
    public function alternatives(): array
    {
        return $this->alternatives;
    }

    /**
     * @internal Used by the ports of the test.
     *
     * @return list<string>
     */
    public function candidates(): array
    {
        return $this->candidates;
    }

    /** @internal Used by the ports of the test. */
    public function assigned(): ?string
    {
        return $this->assigned;
    }

    /** @internal Used by the ports of the test. */
    public function locked(string $collaboratorId): void
    {
        $this->locked[] = $collaboratorId;
    }

    /** @internal Used by the ports of the test. */
    public function serviceExists(): bool
    {
        return $this->serviceExists;
    }

    /** @internal Used by the ports of the test. */
    public function deadlineHours(): int
    {
        return $this->deadlineHours;
    }

    /** @internal Used by the ports of the test. */
    public function collaboratorExists(): bool
    {
        return $this->collaboratorExists;
    }

    /** The request of Ana for Daniela's space of 10:00 on Tuesday 5 March 2030. */
    private function command(?DateTimeImmutable $startsAt = null, bool $withVoucher = false): CreateReservationCommand
    {
        return new CreateReservationCommand(self::ANA_ID, self::SERVICE_ID, self::DANIELA_ID, $startsAt ?? self::at('2030-03-05 10:00'), $withVoucher);
    }

    /** The request of Ana for the space of 10:00 on Tuesday 5 March 2030 with "any available". */
    private function anyCommand(): CreateReservationCommand
    {
        return new CreateReservationCommand(self::ANA_ID, self::SERVICE_ID, null, self::at('2030-03-05 10:00'));
    }

    /** A moment in Costa Rica time. */
    private static function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }
}
