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
use Src\Reservations\Reservations\Application\Contracts\BookableServices;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorLock;
use Src\Reservations\Reservations\Application\Contracts\CustomerMemberships;
use Src\Reservations\Reservations\Application\Contracts\FreeSlots;
use Src\Reservations\Reservations\Application\DTOs\BookableService;
use Src\Reservations\Reservations\Application\DTOs\CreateReservationCommand;
use Src\Reservations\Reservations\Application\UseCases\CreateReservation;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Reservations\Reservations\Domain\Exceptions\OverlappingReservationException;
use Src\Reservations\Reservations\Domain\Exceptions\ServiceNotBookableException;
use Src\Reservations\Reservations\Domain\Exceptions\SlotUnavailableException;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Domain\ValueObjects\SlotAlternative;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Shared\Domain\ValueObjects\UserId;
use Tests\Support\Identity\InMemoryMembershipRepository;
use Tests\Support\Reservations\InMemoryReservationRepository;
use Tests\Support\Shared\RecordingTransactionRunner;

/** Daniela's space of 10:00 is booked by Ana in a barbershop with no payment policy yet (RF-11, RE-01, RE-02, RE-06, RE-07, SV-03, UP-09). */
final class CreateReservationTest extends TestCase
{
    private const string BARBERSHOP_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d22';

    private const string ANA_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    private const string SERVICE_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d33';

    private const string DANIELA_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d55';

    private InMemoryReservationRepository $reservations;

    private InMemoryMembershipRepository $memberships;

    private RecordingTransactionRunner $transaction;

    /** @var list<string> What happened, in order. */
    private array $calls = [];

    /** Whether the calculation of the availability lists the requested space as free. */
    private bool $free = true;

    /** @var list<SlotAlternative> What the availability offers instead. */
    private array $alternatives = [];

    private bool $serviceExists = true;

    private bool $collaboratorExists = true;

    private CreateReservation $createReservation;

    /** Wire the use case to in-memory ports that log their calls. */
    protected function setUp(): void
    {
        $this->reservations = new InMemoryReservationRepository;
        $this->memberships = new InMemoryMembershipRepository;
        $this->transaction = new RecordingTransactionRunner;
        $test = $this;

        $services = new class($test) implements BookableServices
        {
            public function __construct(private readonly CreateReservationTest $test) {}

            public function find(string $serviceId): ?BookableService
            {
                return $this->test->serviceExists() ? new BookableService(Money::of(8500), Duration::ofMinutes(45)) : null;
            }
        };
        $lock = new class($test) implements CollaboratorLock
        {
            public function __construct(private readonly CreateReservationTest $test) {}

            public function lock(string $collaboratorId): void
            {
                $this->test->log('lock');
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
        $customers = new class($test, new EnsureCustomerMembership($this->memberships)) implements CustomerMemberships
        {
            public function __construct(private readonly CreateReservationTest $test, private readonly EnsureCustomerMembership $ensure) {}

            public function ensure(string $userId): string
            {
                $this->test->log('membership');

                return $this->ensure->execute($userId, '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d22');
            }
        };

        $this->createReservation = new CreateReservation($this->reservations, $services, $lock, $slots, $customers, $this->transaction);
    }

    /** The reservation is confirmed at once with the copies of the service, and its history has creation and confirmation (RE-07, SV-03, RE-09). */
    public function test_the_reservation_is_confirmed_with_the_copies_of_the_service(): void
    {
        $reservation = $this->createReservation->execute($this->command(), self::at('2030-03-01 08:00'));

        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame(8500, $reservation->price->colones);
        $this->assertSame(45, $reservation->duration->minutes);
        $this->assertSame(Reservation::DEFAULT_CANCELLATION_DEADLINE_HOURS, $reservation->cancellationDeadlineHours);
        $this->assertSame(self::DANIELA_ID, $reservation->collaboratorId->value);
        $this->assertEquals(self::at('2030-03-05 10:45'), $reservation->interval->end);
        $this->assertSame([2], $this->reservations->entriesPerSave);
        $this->assertNotNull($this->reservations->find($reservation->id));
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

    /** @internal Used by the ports of the test. */
    public function serviceExists(): bool
    {
        return $this->serviceExists;
    }

    /** @internal Used by the ports of the test. */
    public function collaboratorExists(): bool
    {
        return $this->collaboratorExists;
    }

    /** The request of Ana for Daniela's space of 10:00 on Tuesday 5 March 2030. */
    private function command(?DateTimeImmutable $startsAt = null): CreateReservationCommand
    {
        return new CreateReservationCommand(self::ANA_ID, self::SERVICE_ID, self::DANIELA_ID, $startsAt ?? self::at('2030-03-05 10:00'));
    }

    /** A moment in Costa Rica time. */
    private static function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }
}
