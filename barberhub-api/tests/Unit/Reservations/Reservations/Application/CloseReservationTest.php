<?php

declare(strict_types=1);

namespace Tests\Unit\Reservations\Reservations\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorDirectory;
use Src\Reservations\Reservations\Application\UseCases\CloseReservation;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\ReservationNotFoundException;
use Src\Reservations\Reservations\Domain\Exceptions\ReservationNotYetDueException;
use Src\Reservations\Reservations\Domain\Exceptions\TransitionNotAllowedException;
use Src\Reservations\Reservations\Domain\Services\CancellationConsequence;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\PurchasedVoucherId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Domain\ValueObjects\ServiceId;
use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\Money;
use Tests\Support\Reservations\InMemoryReservationRepository;
use Tests\Support\Reservations\RecordingReservationDeposits;
use Tests\Support\Reservations\RecordingReservationVouchers;
use Tests\Support\Shared\RecordingTransactionRunner;

/** Who closes which reservation, when, and why repeating it changes nothing (RF-18, CO-05, RE-04, RE-09, CA-03, CA-06, UP-06). The reservation starts on 5 March at 10:00. */
final class CloseReservationTest extends TestCase
{
    public const string DANIELA_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d61';

    public const string DANIELA = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d55';

    private const string KEVIN_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d62';

    private const string OWNER_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d48';

    private const string ANA_ROLE = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d71';

    private InMemoryReservationRepository $reservations;

    private RecordingTransactionRunner $transaction;

    private RecordingReservationVouchers $vouchers;

    private RecordingReservationDeposits $deposits;

    private string $reservationId;

    /** Daniela has a confirmed reservation on 5 March at 10:00. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->reservations = new InMemoryReservationRepository;
        $this->transaction = new RecordingTransactionRunner;
        $this->vouchers = new RecordingReservationVouchers;
        $this->deposits = new RecordingReservationDeposits;
        $reservation = Reservation::create(
            $this->reservations->nextId(),
            new CollaboratorId(self::DANIELA),
            new ServiceId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d33'),
            new CustomerMembershipId(self::ANA_ROLE),
            self::at('2030-03-05 10:00'),
            Money::of(7000),
            Duration::ofMinutes(45),
            24,
            Author::system(),
            self::at('2030-03-01 08:00'),
        );
        $reservation->confirm(self::at('2030-03-01 08:00'), Author::system());
        $this->reservations->save($reservation);
        $this->reservationId = $reservation->id->value;
    }

    /** The collaborator of the agenda marks it attended at the start, and the change keeps its author and its moment (RE-09). */
    public function test_the_collaborator_marks_their_reservation_attended(): void
    {
        $closed = $this->useCase()->execute(self::DANIELA_USER, false, $this->reservationId, ReservationStatus::Completed, self::at('2030-03-05 10:00'));

        $this->assertSame(ReservationStatus::Completed, $closed->status);
        $last = array_last($this->reservations->history(new ReservationId($this->reservationId)));
        $this->assertSame([ReservationStatus::Confirmed, ReservationStatus::Completed, self::DANIELA_USER], [$last?->from, $last?->to, $last?->author->user?->value]);
        $this->assertEquals(self::at('2030-03-05 10:00'), $last?->at);
        $this->assertSame(1, $this->transaction->committed());
    }

    /** The owner closes any reservation of the barbershop (CO-05). */
    public function test_the_owner_marks_any_reservation(): void
    {
        $closed = $this->useCase()->execute(self::OWNER_USER, true, $this->reservationId, ReservationStatus::NoShow, self::at('2030-03-05 10:10'));

        $this->assertSame(ReservationStatus::NoShow, $closed->status);
    }

    /** Before the start nothing is accepted, and an absence also waits for the tolerance of 10 minutes (CO-05). */
    public function test_the_time_rules_refuse_early_markings(): void
    {
        foreach ([[ReservationStatus::Completed, '2030-03-05 09:59'], [ReservationStatus::NoShow, '2030-03-05 10:09']] as [$outcome, $moment]) {
            try {
                $this->useCase()->execute(self::OWNER_USER, true, $this->reservationId, $outcome, self::at($moment));
                $this->fail("{$outcome->value} was accepted at {$moment}.");
            } catch (ReservationNotYetDueException) {
                $this->assertSame(ReservationStatus::Confirmed, $this->reservations->find(new ReservationId($this->reservationId))?->status);
            }
        }

        $this->assertSame([], $this->vouchers->calls);
    }

    /** A collaborator with another agenda, or with none, reads the reservation as a missing one and nothing changes (CO-05, UP-06). */
    public function test_another_collaborator_does_not_reach_the_reservation(): void
    {
        foreach ([self::KEVIN_USER, '0192f0c4-0000-7000-8000-000000000999'] as $user) {
            try {
                $this->useCase()->execute($user, false, $this->reservationId, ReservationStatus::Completed, self::at('2030-03-05 11:00'));
                $this->fail('A collaborator closed a reservation that is not theirs.');
            } catch (ReservationNotFoundException) {
                $this->assertSame(ReservationStatus::Confirmed, $this->reservations->find(new ReservationId($this->reservationId))?->status);
            }
        }
    }

    /** A reservation that does not exist is not found. */
    public function test_an_unknown_reservation_is_not_found(): void
    {
        $this->expectException(ReservationNotFoundException::class);

        $this->useCase()->execute(self::OWNER_USER, true, '0192f0c4-0000-7000-8000-999999999999', ReservationStatus::Completed, self::at('2030-03-05 11:00'));
    }

    /** A final status does not change: the other outcome is refused and the first stays (RE-04). */
    public function test_a_final_status_does_not_change(): void
    {
        $this->useCase()->execute(self::OWNER_USER, true, $this->reservationId, ReservationStatus::Completed, self::at('2030-03-05 11:00'));

        try {
            $this->useCase()->execute(self::OWNER_USER, true, $this->reservationId, ReservationStatus::NoShow, self::at('2030-03-05 12:00'));
            $this->fail('A completed reservation became an absence.');
        } catch (TransitionNotAllowedException) {
            $this->assertSame(ReservationStatus::Completed, $this->reservations->find(new ReservationId($this->reservationId))?->status);
        }
    }

    /** A temporary reservation cannot be closed, because it is not confirmed (RE-04). */
    public function test_a_temporary_reservation_cannot_be_closed(): void
    {
        $temporary = Reservation::create(
            $this->reservations->nextId(),
            new CollaboratorId(self::DANIELA),
            new ServiceId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d33'),
            new CustomerMembershipId(self::ANA_ROLE),
            self::at('2030-03-06 10:00'),
            Money::of(7000),
            Duration::ofMinutes(45),
            24,
            Author::system(),
            self::at('2030-03-01 08:00'),
        );
        $this->reservations->save($temporary);

        $this->expectException(TransitionNotAllowedException::class);

        $this->useCase()->execute(self::OWNER_USER, true, $temporary->id->value, ReservationStatus::Completed, self::at('2030-03-06 11:00'));
    }

    /** Repeating the same outcome saves nothing, adds no entry and does not use the session again (CA-06). */
    public function test_a_repeat_writes_nothing(): void
    {
        $this->payWithVoucher();

        $this->useCase()->execute(self::OWNER_USER, true, $this->reservationId, ReservationStatus::NoShow, self::at('2030-03-05 10:10'));
        $again = $this->useCase()->execute(self::OWNER_USER, true, $this->reservationId, ReservationStatus::NoShow, self::at('2030-03-05 11:00'));

        $this->assertSame(ReservationStatus::NoShow, $again->status);
        $this->assertCount(3, $this->reservations->history(new ReservationId($this->reservationId)));
        $this->assertSame(['consume'], $this->vouchers->operations());
    }

    /** An absence leaves the session of the voucher used, and one paid another way settles nothing (CA-03, BO-05). */
    public function test_an_absence_consumes_the_session_of_the_voucher(): void
    {
        $this->useCase()->execute(self::OWNER_USER, true, $this->reservationId, ReservationStatus::NoShow, self::at('2030-03-05 10:10'));
        $this->assertSame([], $this->vouchers->calls);

        $this->setUp();
        $this->payWithVoucher();
        $this->useCase()->execute(self::OWNER_USER, true, $this->reservationId, ReservationStatus::NoShow, self::at('2030-03-05 10:10'));

        $this->assertSame([['consume', RecordingReservationVouchers::VOUCHER, $this->reservationId]], $this->vouchers->calls);
    }

    /** Attending also leaves the session used, once, and never gives it back (BO-05). */
    public function test_attending_leaves_the_session_used(): void
    {
        $this->payWithVoucher();

        $this->useCase()->execute(self::DANIELA_USER, false, $this->reservationId, ReservationStatus::Completed, self::at('2030-03-05 10:30'));

        $this->assertSame([['consume', RecordingReservationVouchers::VOUCHER, $this->reservationId]], $this->vouchers->calls);
    }

    /** The deposit of an absence is retained and of an attended reservation applied: neither marks anything to give back (CA-03). */
    public function test_closing_never_marks_the_deposit_to_give_back(): void
    {
        $this->deposits->paid = true;
        $this->useCase()->execute(self::OWNER_USER, true, $this->reservationId, ReservationStatus::NoShow, self::at('2030-03-05 10:10'));
        $this->assertSame([], $this->deposits->marked);

        $this->setUp();
        $this->deposits->paid = true;
        $this->useCase()->execute(self::OWNER_USER, true, $this->reservationId, ReservationStatus::Completed, self::at('2030-03-05 10:30'));
        $this->assertSame([], $this->deposits->marked);
    }

    /** Attach the voucher to the reservation, as the creation does. */
    private function payWithVoucher(): void
    {
        $reservation = $this->reservations->find(new ReservationId($this->reservationId)) ?? throw new \LogicException;
        $reservation->payWithVoucher(new PurchasedVoucherId(RecordingReservationVouchers::VOUCHER));
        $this->reservations->save($reservation);
    }

    private function useCase(): CloseReservation
    {
        $collaborators = new class implements CollaboratorDirectory
        {
            public function idOfUser(string $userId): ?string
            {
                return $userId === CloseReservationTest::DANIELA_USER ? CloseReservationTest::DANIELA : null;
            }

            public function names(): array
            {
                return [];
            }
        };

        return new CloseReservation($this->reservations, $collaborators, $this->transaction, $this->vouchers, $this->deposits, new CancellationConsequence);
    }

    private static function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }
}
