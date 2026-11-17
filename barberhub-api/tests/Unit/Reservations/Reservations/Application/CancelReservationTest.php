<?php

declare(strict_types=1);

namespace Tests\Unit\Reservations\Reservations\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Reservations\Reservations\Application\Contracts\CustomerDirectory;
use Src\Reservations\Reservations\Application\UseCases\CancelReservation;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\ReservationNotFoundException;
use Src\Reservations\Reservations\Domain\Exceptions\TransitionNotAllowedException;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CancellationClass;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\PurchasedVoucherId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Domain\ValueObjects\ServiceId;
use Src\Shared\Domain\ValueObjects\AuditAction;
use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\UserId;
use Tests\Support\Reservations\InMemoryReservationRepository;
use Tests\Support\Reservations\RecordingReservationVouchers;
use Tests\Support\Shared\InMemoryAuditLog;
use Tests\Support\Shared\RecordingTransactionRunner;

/** Who may cancel which reservation, how the cancellation is classified and why repeating it changes nothing (RF-16, CA-01, CA-04, CA-06). */
final class CancelReservationTest extends TestCase
{
    public const string ANA_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    public const string ANA_ROLE = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d71';

    private const string LUIS_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d45';

    private const string OWNER_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d48';

    private InMemoryReservationRepository $reservations;

    private RecordingTransactionRunner $transaction;

    private InMemoryAuditLog $audit;

    private RecordingReservationVouchers $vouchers;

    private string $reservationId;

    /** Ana has a confirmed reservation on 5 March at 10:00 with a deadline of 24 hours. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->reservations = new InMemoryReservationRepository;
        $this->transaction = new RecordingTransactionRunner;
        $this->audit = new InMemoryAuditLog;
        $this->vouchers = new RecordingReservationVouchers;
        $reservation = Reservation::create(
            $this->reservations->nextId(),
            new CollaboratorId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d55'),
            new ServiceId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d33'),
            new CustomerMembershipId(self::ANA_ROLE),
            self::at('2030-03-05 10:00'),
            Money::of(7000),
            Duration::ofMinutes(45),
            24,
            Author::person(new UserId(self::ANA_USER)),
            self::at('2030-03-01 08:00'),
        );
        $reservation->confirm(self::at('2030-03-01 08:00'), Author::system());
        $this->reservations->save($reservation);
        $this->reservationId = $reservation->id->value;
    }

    /** At the very deadline the cancellation is still on time; a minute later it is late (CA-01). */
    public function test_the_deadline_is_the_limit_of_the_on_time_class(): void
    {
        $atTheDeadline = $this->useCase()->execute(self::ANA_USER, false, $this->reservationId, self::at('2030-03-04 10:00'));

        $this->assertSame(ReservationStatus::CancelledByCustomer, $atTheDeadline->reservation->status);
        $this->assertSame(CancellationClass::OnTime, $atTheDeadline->class);

        $this->setUp();
        $late = $this->useCase()->execute(self::ANA_USER, false, $this->reservationId, self::at('2030-03-04 10:01'));

        $this->assertSame(CancellationClass::Late, $late->class);
    }

    /** The owner's cancellation is the barbershop's, whatever the time (CA-04). */
    public function test_the_owner_cancels_for_the_barbershop(): void
    {
        $cancelled = $this->useCase()->execute(self::OWNER_USER, true, $this->reservationId, self::at('2030-03-05 09:00'));

        $this->assertSame(ReservationStatus::CancelledByBarbershop, $cancelled->reservation->status);
        $this->assertSame(CancellationClass::ByBarbershop, $cancelled->class);
    }

    /** Repeating the cancellation saves nothing and keeps the class of the first one (CA-06). */
    public function test_a_repeat_writes_nothing_and_keeps_the_class(): void
    {
        $first = $this->useCase()->execute(self::ANA_USER, false, $this->reservationId, self::at('2030-03-04 09:00'));
        $second = $this->useCase()->execute(self::ANA_USER, false, $this->reservationId, self::at('2030-03-05 09:00'));

        $this->assertSame(CancellationClass::OnTime, $first->class);
        $this->assertSame(CancellationClass::OnTime, $second->class);
        $this->assertSame([2, 1], $this->reservations->entriesPerSave);
        $this->assertCount(3, $this->reservations->history(new ReservationId($this->reservationId)));
    }

    /** The customer of another reservation reads it as a missing one, and nothing changes (UP-06). */
    public function test_another_customer_does_not_reach_the_reservation(): void
    {
        $this->expectException(ReservationNotFoundException::class);

        try {
            $this->useCase()->execute(self::LUIS_USER, false, $this->reservationId, self::at('2030-03-02 09:00'));
        } finally {
            $this->assertSame(ReservationStatus::Confirmed, $this->reservations->find(new ReservationId($this->reservationId))?->status);
        }
    }

    /** A reservation that does not exist is not found. */
    public function test_an_unknown_reservation_is_not_found(): void
    {
        $this->expectException(ReservationNotFoundException::class);

        $this->useCase()->execute(self::OWNER_USER, true, '0192f0c4-0000-7000-8000-999999999999', self::at('2030-03-02 09:00'));
    }

    /** A reservation already attended cannot be cancelled by the customer or by the owner (RE-04). */
    public function test_a_completed_reservation_cannot_be_cancelled(): void
    {
        $reservation = $this->reservations->find(new ReservationId($this->reservationId));
        $reservation?->complete(self::at('2030-03-05 11:00'), Author::system());
        $this->reservations->save($reservation ?? throw new \LogicException);

        foreach ([[self::ANA_USER, false], [self::OWNER_USER, true]] as [$user, $isOwner]) {
            try {
                $this->useCase()->execute($user, $isOwner, $this->reservationId, self::at('2030-03-06 09:00'));
                $this->fail('A completed reservation was cancelled.');
            } catch (TransitionNotAllowedException) {
                $this->assertSame(ReservationStatus::Completed, $this->reservations->find(new ReservationId($this->reservationId))?->status);
            }
        }
    }

    /** The cancellation runs in one transaction, which commits. */
    public function test_the_cancellation_runs_in_one_transaction(): void
    {
        $this->useCase()->execute(self::ANA_USER, false, $this->reservationId, self::at('2030-03-02 09:00'));

        $this->assertSame(1, $this->transaction->runs());
        $this->assertSame(1, $this->transaction->committed());
    }

    /** The cancellation by the barbershop leaves one entry with the author, the instant and the status before and after (SE-04). */
    public function test_a_cancellation_by_the_barbershop_leaves_an_entry(): void
    {
        $now = self::at('2030-03-02 09:00');

        $this->useCase()->execute(self::OWNER_USER, true, $this->reservationId, $now);

        $entries = $this->audit->entries();
        $this->assertCount(1, $entries);
        $this->assertSame([AuditAction::ReservationCancelledByBarbershop, self::OWNER_USER, 'reservation', $this->reservationId], [$entries[0]->action, $entries[0]->actor->value, $entries[0]->subjectType, $entries[0]->subjectId]);
        $this->assertEquals($now, $entries[0]->at);
        $this->assertSame(['status' => ['previous' => 'confirmed', 'new' => 'cancelled_by_barbershop']], $entries[0]->changes);
    }

    /** The cancellation by the customer is not one of the sensitive actions, and repeating the one of the barbershop adds no second entry. */
    public function test_a_cancellation_by_the_customer_and_a_repeat_leave_no_entry(): void
    {
        $this->useCase()->execute(self::ANA_USER, false, $this->reservationId, self::at('2030-03-02 09:00'));
        $this->assertSame([], $this->audit->entries());

        $this->setUp();
        $this->useCase()->execute(self::OWNER_USER, true, $this->reservationId, self::at('2030-03-02 09:00'));
        $this->useCase()->execute(self::OWNER_USER, true, $this->reservationId, self::at('2030-03-03 09:00'));
        $this->assertCount(1, $this->audit->entries());
    }

    /** A cancellation on time gives the session of the voucher back, once, and a reservation paid another way settles nothing (RF-17, BO-05, CA-02). */
    public function test_an_on_time_cancellation_gives_the_session_back(): void
    {
        $this->useCase()->execute(self::ANA_USER, false, $this->reservationId, self::at('2030-03-04 09:00'));
        $this->assertSame([], $this->vouchers->calls);

        $this->setUp();
        $this->payWithVoucher();
        $this->useCase()->execute(self::ANA_USER, false, $this->reservationId, self::at('2030-03-04 09:00'));

        $this->assertSame([['release', RecordingReservationVouchers::VOUCHER, $this->reservationId]], $this->vouchers->calls);
        $this->assertSame(1, $this->transaction->committed());
    }

    /** The cancellation of the barbershop gives the session back whatever the time (BO-05, CA-04). */
    public function test_a_cancellation_by_the_barbershop_gives_the_session_back(): void
    {
        $this->payWithVoucher();

        $this->useCase()->execute(self::OWNER_USER, true, $this->reservationId, self::at('2030-03-05 09:30'));

        $this->assertSame(['release'], $this->vouchers->operations());
    }

    /** A late cancellation leaves the session used: the balance is not given back (BO-05, CA-03). */
    public function test_a_late_cancellation_leaves_the_session_used(): void
    {
        $this->payWithVoucher();

        $this->useCase()->execute(self::ANA_USER, false, $this->reservationId, self::at('2030-03-04 10:01'));

        $this->assertSame([['consume', RecordingReservationVouchers::VOUCHER, $this->reservationId]], $this->vouchers->calls);
    }

    /** Repeating the cancellation does not settle the session again (CA-06). */
    public function test_repeating_the_cancellation_settles_the_session_once(): void
    {
        $this->payWithVoucher();

        $this->useCase()->execute(self::ANA_USER, false, $this->reservationId, self::at('2030-03-04 09:00'));
        $this->useCase()->execute(self::ANA_USER, false, $this->reservationId, self::at('2030-03-04 09:30'));

        $this->assertSame(['release'], $this->vouchers->operations());
    }

    /** Attach the voucher to the reservation of Ana, as the creation does. */
    private function payWithVoucher(): void
    {
        $reservation = $this->reservations->find(new ReservationId($this->reservationId)) ?? throw new \LogicException;
        $reservation->payWithVoucher(new PurchasedVoucherId(RecordingReservationVouchers::VOUCHER));
        $this->reservations->save($reservation);
    }

    private function useCase(): CancelReservation
    {
        $customers = new class implements CustomerDirectory
        {
            public function find(string $userId): ?string
            {
                return $userId === CancelReservationTest::ANA_USER ? CancelReservationTest::ANA_ROLE : null;
            }

            public function namesOf(array $membershipIds): array
            {
                return [];
            }
        };

        return new CancelReservation($this->reservations, $customers, $this->transaction, $this->audit, $this->vouchers);
    }

    private static function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }
}
