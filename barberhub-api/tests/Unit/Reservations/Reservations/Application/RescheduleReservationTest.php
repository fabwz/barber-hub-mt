<?php

declare(strict_types=1);

namespace Tests\Unit\Reservations\Reservations\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorLock;
use Src\Reservations\Reservations\Application\Contracts\CustomerDirectory;
use Src\Reservations\Reservations\Application\Contracts\FreeSlots;
use Src\Reservations\Reservations\Application\UseCases\RescheduleReservation;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Reservations\Reservations\Domain\Exceptions\RescheduleDeadlineExpiredException;
use Src\Reservations\Reservations\Domain\Exceptions\ReservationNotFoundException;
use Src\Reservations\Reservations\Domain\Exceptions\SlotUnavailableException;
use Src\Reservations\Reservations\Domain\Exceptions\TransitionNotAllowedException;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Domain\ValueObjects\ServiceId;
use Src\Reservations\Reservations\Domain\ValueObjects\SlotAlternative;
use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Shared\Domain\ValueObjects\UserId;
use Tests\Support\Reservations\InMemoryReservationRepository;
use Tests\Support\Shared\RecordingTransactionRunner;

/** Who may move which reservation, in what order the use case locks and revalidates, and what it does when the space is taken (RF-15, RE-08, CA-01, CA-07). */
final class RescheduleReservationTest extends TestCase
{
    public const string ANA_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    public const string ANA_ROLE = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d71';

    private const string LUIS_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d45';

    private const string OWNER_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d48';

    private const string DANIELA = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d55';

    private const string KEVIN = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d56';

    private const string SERVICE = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d33';

    /** @var list<string> What happened, in order. */
    public array $calls = [];

    public bool $free = true;

    public bool $collaboratorExists = true;

    private InMemoryReservationRepository $reservations;

    private RecordingTransactionRunner $transaction;

    private string $reservationId;

    /** Ana has a confirmed reservation with Daniela on 5 March at 10:00, with a deadline of 4 hours. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->reservations = new InMemoryReservationRepository;
        $this->transaction = new RecordingTransactionRunner;
        $reservation = Reservation::create(
            $this->reservations->nextId(),
            new CollaboratorId(self::DANIELA),
            new ServiceId(self::SERVICE),
            new CustomerMembershipId(self::ANA_ROLE),
            self::at('2030-03-05 10:00'),
            Money::of(7000),
            Duration::ofMinutes(45),
            4,
            Author::person(new UserId(self::ANA_USER)),
            self::at('2030-03-01 08:00'),
        );
        $reservation->confirm(self::at('2030-03-01 08:00'), Author::system());
        $this->reservations->save($reservation);
        $this->reservationId = $reservation->id->value;
    }

    /** The customer moves their own reservation: it is locked, revalidated leaving itself out, and saved with one more history entry. */
    public function test_the_customer_moves_their_own_reservation(): void
    {
        $moved = $this->useCase()->execute(self::ANA_USER, false, $this->reservationId, self::at('2030-03-05 10:15'), null, self::at('2030-03-05 05:00'));

        $this->assertSame(['lock '.self::DANIELA, 'free '.self::DANIELA.' ignoring '.$this->reservationId], $this->calls);
        $this->assertEquals(self::at('2030-03-05 10:15'), $moved->interval->start);
        $this->assertSame(1, $moved->rescheduleCount);
        $this->assertSame([2, 1], $this->reservations->entriesPerSave);
        $this->assertSame(1, $this->transaction->committed());
    }

    /** Naming another collaborator locks and revalidates that one (RF-15). */
    public function test_another_collaborator_is_the_one_locked_and_checked(): void
    {
        $moved = $this->useCase()->execute(self::ANA_USER, false, $this->reservationId, self::at('2030-03-05 11:00'), self::KEVIN, self::at('2030-03-04 09:00'));

        $this->assertSame(self::KEVIN, $moved->collaboratorId->value);
        $this->assertSame(['lock '.self::KEVIN, 'free '.self::KEVIN.' ignoring '.$this->reservationId], $this->calls);
    }

    /** The deadline of the customer is checked before the space, so a late customer is told about the deadline (CA-01). */
    public function test_a_late_customer_gets_the_deadline_error_before_any_check(): void
    {
        try {
            $this->useCase()->execute(self::ANA_USER, false, $this->reservationId, self::at('2030-03-05 11:00'), null, self::at('2030-03-05 07:00'));
            $this->fail('The deadline had passed.');
        } catch (RescheduleDeadlineExpiredException) {
            $this->assertSame([], $this->calls);
            $this->assertSame(1, $this->transaction->rolledBack());
        }
    }

    /** The owner moves any reservation of the barbershop, without a deadline (CA-07). */
    public function test_the_owner_moves_it_without_a_deadline(): void
    {
        $moved = $this->useCase()->execute(self::OWNER_USER, true, $this->reservationId, self::at('2030-03-05 12:00'), null, self::at('2030-03-05 09:50'));

        $this->assertSame(1, $moved->rescheduleCount);
    }

    /** The reservation of another customer reads as a missing one, and a missing one as well (UP-06). */
    public function test_a_foreign_or_unknown_reservation_is_not_found(): void
    {
        foreach ([[self::LUIS_USER, false, $this->reservationId], [self::OWNER_USER, true, '0192f0c4-0000-7000-8000-999999999999']] as [$user, $isOwner, $id]) {
            try {
                $this->useCase()->execute($user, $isOwner, $id, self::at('2030-03-05 12:00'), null, self::at('2030-03-02 09:00'));
                $this->fail('The reservation was reached.');
            } catch (ReservationNotFoundException) {
                $this->assertSame([], $this->calls);
            }
        }
    }

    /** A taken space brings the alternatives, read leaving the reservation itself out, and saves nothing (RE-08, RE-02). */
    public function test_a_taken_space_brings_alternatives_and_saves_nothing(): void
    {
        $this->free = false;

        try {
            $this->useCase()->execute(self::ANA_USER, false, $this->reservationId, self::at('2030-03-05 12:00'), null, self::at('2030-03-02 09:00'));
            $this->fail('The space was taken.');
        } catch (SlotUnavailableException $exception) {
            $this->assertCount(1, $exception->alternatives());
            $this->assertSame(
                ['lock '.self::DANIELA, 'free '.self::DANIELA.' ignoring '.$this->reservationId, 'alternatives '.self::DANIELA.' ignoring '.$this->reservationId],
                $this->calls,
            );
            $this->assertSame([2], $this->reservations->entriesPerSave);
            $this->assertSame(1, $this->transaction->rolledBack());
        }
    }

    /** When the exclusion of the database rejects the move after the check said free, the error is the same (second barrier, ADR 0009). */
    public function test_the_exclusion_of_the_database_is_translated_the_same(): void
    {
        $this->reservations->collideOnSave = true;

        try {
            $this->useCase()->execute(self::ANA_USER, false, $this->reservationId, self::at('2030-03-05 12:00'), null, self::at('2030-03-02 09:00'));
            $this->fail('The database accepted the collision.');
        } catch (SlotUnavailableException $exception) {
            $this->assertCount(1, $exception->alternatives());
        }
    }

    /** A collaborator that is not in the barbershop is not found (RF-15). */
    public function test_an_unknown_collaborator_is_not_found(): void
    {
        $this->collaboratorExists = false;

        $this->expectException(CollaboratorNotFoundException::class);

        $this->useCase()->execute(self::ANA_USER, false, $this->reservationId, self::at('2030-03-05 12:00'), self::KEVIN, self::at('2030-03-02 09:00'));
    }

    /** A reservation that is not confirmed is not moved (RE-04). */
    public function test_a_cancelled_reservation_is_not_moved(): void
    {
        $reservation = $this->reservations->find(new ReservationId($this->reservationId));
        $reservation?->cancelByCustomer(self::at('2030-03-02 09:00'), Author::system());

        $this->expectException(TransitionNotAllowedException::class);

        try {
            $this->useCase()->execute(self::ANA_USER, false, $this->reservationId, self::at('2030-03-05 12:00'), null, self::at('2030-03-02 09:00'));
        } finally {
            $this->assertSame(ReservationStatus::CancelledByCustomer, $reservation?->status);
        }
    }

    private function useCase(): RescheduleReservation
    {
        $test = $this;
        $customers = new class implements CustomerDirectory
        {
            public function find(string $userId): ?string
            {
                return $userId === RescheduleReservationTest::ANA_USER ? RescheduleReservationTest::ANA_ROLE : null;
            }

            public function namesOf(array $membershipIds): array
            {
                return [];
            }
        };
        $lock = new class($test) implements CollaboratorLock
        {
            public function __construct(private readonly RescheduleReservationTest $test) {}

            public function lock(string $collaboratorId): void
            {
                $this->test->calls[] = "lock {$collaboratorId}";
                $this->test->collaboratorExists || throw CollaboratorNotFoundException::create();
            }
        };
        $slots = new class($test) implements FreeSlots
        {
            public function __construct(private readonly RescheduleReservationTest $test) {}

            public function isFree(string $serviceId, string $collaboratorId, DateTimeImmutable $startsAt, DateTimeImmutable $now, ?string $ignoringReservationId = null): bool
            {
                $this->test->calls[] = "free {$collaboratorId} ignoring {$ignoringReservationId}";

                return $this->test->free;
            }

            public function alternatives(string $serviceId, string $collaboratorId, DateTimeImmutable $requested, DateTimeImmutable $now, ?string $ignoringReservationId = null): array
            {
                $this->test->calls[] = "alternatives {$collaboratorId} ignoring {$ignoringReservationId}";

                return [new SlotAlternative(new CollaboratorId($collaboratorId), TimeInterval::between($requested->modify('+1 hour'), $requested->modify('+105 minutes')))];
            }
        };

        return new RescheduleReservation($this->reservations, $customers, $lock, $slots, $this->transaction);
    }

    private static function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }
}
