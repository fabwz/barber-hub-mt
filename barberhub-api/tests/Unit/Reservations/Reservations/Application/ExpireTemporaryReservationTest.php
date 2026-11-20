<?php

declare(strict_types=1);

namespace Tests\Unit\Reservations\Reservations\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorLock;
use Src\Reservations\Reservations\Application\UseCases\ExpireOverdueReservations;
use Src\Reservations\Reservations\Application\UseCases\ExpireTemporaryReservation;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Events\SpaceReleased;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Domain\ValueObjects\ServiceId;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\UserId;
use Tests\Support\Reservations\InMemoryReservationRepository;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Shared\RecordingDomainEvents;
use Tests\Support\Shared\RecordingTransactionRunner;

/** The task that closes the payment window of a temporary reservation: it expires it on time, frees its space and never touches what was paid or cancelled (RE-05, DI-05, RF-31). */
final class ExpireTemporaryReservationTest extends TestCase
{
    private const string BARBERSHOP = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d10';

    private const string ANA_ROLE = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d71';

    private const string DANIELA = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d55';

    private InMemoryReservationRepository $reservations;

    private RecordingDomainEvents $events;

    private RecordingTransactionRunner $transaction;

    /** @var list<string> What was locked, in order. */
    private array $locked = [];

    private ExpireTemporaryReservation $useCase;

    /** Wire the use case to in-memory ports; the lock only records who was locked. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->reservations = new InMemoryReservationRepository;
        $this->events = new RecordingDomainEvents;
        $this->transaction = new RecordingTransactionRunner;
        $test = $this;
        $lock = new class($test) implements CollaboratorLock
        {
            public function __construct(private readonly ExpireTemporaryReservationTest $test) {}

            public function lock(string $collaboratorId): void
            {
                $this->test->locked($collaboratorId);
            }
        };
        $this->useCase = new ExpireTemporaryReservation(
            $this->reservations,
            $lock,
            new ExpireOverdueReservations($this->reservations, $this->events, new FixedBarbershopContext(new BarbershopId(self::BARBERSHOP))),
            $this->transaction,
        );
    }

    /** Record that the lock of a collaborator was taken. */
    public function locked(string $collaboratorId): void
    {
        $this->locked[] = $collaboratorId;
    }

    /** Once the window closes the reservation expires by the system, and its space is announced as released (RE-05, RF-31). */
    public function test_it_expires_the_reservation_when_its_window_closes_and_announces_the_space(): void
    {
        $id = $this->temporary();

        $expired = $this->useCase->execute($id, self::at('2030-03-01 08:10:01'));

        $this->assertTrue($expired);
        $reservation = $this->reservations->find(new ReservationId($id));
        $this->assertSame(ReservationStatus::Expired, $reservation?->status);
        $this->assertTrue($this->reservations->history(new ReservationId($id))[1]->author->isSystem());
        $released = $this->events->of(SpaceReleased::class);
        $this->assertCount(1, $released);
        $this->assertSame($id, $released[0]->reservationId);
        $this->assertSame(self::DANIELA, $released[0]->collaboratorId);
    }

    /** It locks the collaborator before it changes anything, as every operation on their time does (RE-01). */
    public function test_it_locks_the_collaborator_of_the_reservation(): void
    {
        $this->useCase->execute($this->temporary(), self::at('2030-03-01 08:11'));

        $this->assertSame([self::DANIELA], $this->locked);
        $this->assertSame(1, $this->transaction->committed());
    }

    /** At the very instant the window closes the reservation is overdue; one second before, it is not and nothing happens (RE-05). */
    public function test_an_early_task_changes_nothing(): void
    {
        $id = $this->temporary();

        $this->assertFalse($this->useCase->execute($id, self::at('2030-03-01 08:09:59')));
        $this->assertSame(ReservationStatus::Temporary, $this->reservations->find(new ReservationId($id))?->status);
        $this->assertSame([], $this->locked);
        $this->assertSame([], $this->events->all());

        $this->assertTrue($this->useCase->execute($id, self::at('2030-03-01 08:10:00')));
    }

    /** A reservation whose payment confirmed it is left alone, and so is one the customer cancelled or that already expired. */
    public function test_a_paid_cancelled_or_expired_reservation_is_left_alone(): void
    {
        $paid = $this->temporary();
        $this->reservations->find(new ReservationId($paid))?->confirm(self::at('2030-03-01 08:05'), Author::system());
        $cancelled = $this->temporary();
        $this->reservations->find(new ReservationId($cancelled))?->cancelByCustomer(self::at('2030-03-01 08:05'), Author::system());
        $expired = $this->temporary();
        $this->reservations->find(new ReservationId($expired))?->expire(self::at('2030-03-01 08:10'), Author::system());

        foreach ([$paid, $cancelled, $expired] as $id) {
            $this->assertFalse($this->useCase->execute($id, self::at('2030-03-01 09:00')));
        }

        $this->assertSame([], $this->events->all());
        $this->assertSame([], $this->locked);
    }

    /** Running it twice, as a retry of the queue would, announces the space once (RNF-09). */
    public function test_running_it_twice_announces_the_space_once(): void
    {
        $id = $this->temporary();

        $this->assertTrue($this->useCase->execute($id, self::at('2030-03-01 08:11')));
        $this->assertFalse($this->useCase->execute($id, self::at('2030-03-01 08:12')));

        $this->assertCount(1, $this->events->of(SpaceReleased::class));
    }

    /** A reservation that does not exist, as one of another barbershop reads, changes nothing. */
    public function test_an_unknown_reservation_changes_nothing(): void
    {
        $this->assertFalse($this->useCase->execute('0192f0c4-0000-7000-8000-00000000ffff', self::at('2030-03-01 09:00')));
        $this->assertSame([], $this->events->all());
    }

    /** Save a temporary reservation of Ana with Daniela made at 8:00, whose window closes at 8:10, and return its identifier. */
    private function temporary(): string
    {
        $reservation = Reservation::create(
            $this->reservations->nextId(),
            new CollaboratorId(self::DANIELA),
            new ServiceId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d33'),
            new CustomerMembershipId(self::ANA_ROLE),
            self::at('2030-03-05 10:00'),
            Money::of(7000),
            Duration::ofMinutes(45),
            24,
            Author::person(new UserId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44')),
            self::at('2030-03-01 08:00'),
        );
        $this->reservations->save($reservation);

        return $reservation->id->value;
    }

    private static function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }
}
