<?php

declare(strict_types=1);

namespace Tests\Unit\Reservations\Reservations\Domain;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\PaymentWindowExpiredException;
use Src\Reservations\Reservations\Domain\Exceptions\RescheduleDeadlineExpiredException;
use Src\Reservations\Reservations\Domain\Exceptions\RescheduleLimitReachedException;
use Src\Reservations\Reservations\Domain\Exceptions\TransitionNotAllowedException;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CancellationClass;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Domain\ValueObjects\ServiceId;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Shared\Domain\ValueObjects\UserId;

final class ReservationTest extends TestCase
{
    private const string RESERVATION_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d01';

    private const string COLLABORATOR_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d02';

    private const string SERVICE_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d03';

    private const string CUSTOMER_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d04';

    private const string USER_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d05';

    private const string OTHER_COLLABORATOR_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d06';

    /** A new reservation is temporary, has one service and one collaborator and no reschedules (RE-03, RE-05). */
    public function test_a_new_reservation_is_temporary(): void
    {
        $reservation = $this->reservation();

        $this->assertSame(ReservationStatus::Temporary, $reservation->status);
        $this->assertSame(self::COLLABORATOR_ID, $reservation->collaboratorId->value);
        $this->assertSame(self::SERVICE_ID, $reservation->serviceId->value);
        $this->assertSame(self::CUSTOMER_ID, $reservation->customerMembershipId->value);
        $this->assertSame(0, $reservation->rescheduleCount);
    }

    /** A new reservation expires 10 minutes after it was made; confirming it clears the deadline (RE-05). */
    public function test_a_new_reservation_has_a_payment_window_that_confirming_clears(): void
    {
        $reservation = $this->reservation();

        $this->assertEquals(self::at('2030-03-01 08:10'), $reservation->expiresAt);

        $reservation->confirm(self::at('2030-03-01 08:09'), Author::system());

        $this->assertNull($reservation->expiresAt);
    }

    /** The window is open until its last instant and closed from the deadline on, even if nobody marked the status (DI-05). */
    public function test_a_temporary_reservation_is_overdue_from_its_deadline(): void
    {
        $reservation = $this->reservation();

        $this->assertFalse($reservation->isOverdueAt(self::at('2030-03-01 08:09:59')));
        $this->assertTrue($reservation->isOverdueAt(self::at('2030-03-01 08:10')));
        $this->assertSame(ReservationStatus::Temporary, $reservation->statusAt(self::at('2030-03-01 08:09:59')));
        $this->assertSame(ReservationStatus::Expired, $reservation->statusAt(self::at('2030-03-01 08:10')));
        $this->assertSame(ReservationStatus::Temporary, $reservation->status);
    }

    /** An overdue reservation cannot be confirmed and stays as it was (RE-05). */
    public function test_an_overdue_reservation_cannot_be_confirmed(): void
    {
        $reservation = $this->reservation();

        $this->expectException(PaymentWindowExpiredException::class);

        try {
            $reservation->confirm(self::at('2030-03-01 08:10'), Author::system());
        } finally {
            $this->assertSame(ReservationStatus::Temporary, $reservation->status);
        }
    }

    /** Expiring clears the deadline: only a temporary reservation has one. */
    public function test_expiring_clears_the_deadline(): void
    {
        $reservation = $this->reservation();

        $reservation->expire(self::at('2030-03-01 08:11'), Author::system());

        $this->assertNull($reservation->expiresAt);
        $this->assertSame(ReservationStatus::Expired, $reservation->statusAt(self::at('2030-03-01 08:30')));
    }

    /** The reservation keeps the price, the duration and the cancellation deadline it was booked with (SV-03, CA-01). */
    public function test_the_reservation_keeps_copies_of_the_service(): void
    {
        $reservation = $this->reservation();

        $this->assertSame(8500, $reservation->price->colones);
        $this->assertSame(45, $reservation->duration->minutes);
        $this->assertSame(4, $reservation->cancellationDeadlineHours);
    }

    /** The service ends when its duration is over and the collaborator stays blocked five more minutes (DI-01). */
    public function test_the_block_is_the_end_of_the_service_plus_the_cleanup(): void
    {
        $reservation = $this->reservation();

        $this->assertEquals(self::at('2030-03-04 10:00'), $reservation->interval->start);
        $this->assertEquals(self::at('2030-03-04 10:45'), $reservation->interval->end);
        $this->assertEquals(self::at('2030-03-04 10:50'), $reservation->blockedUntil);
    }

    /** The block crosses midnight and the end of the month like any other instant. */
    public function test_the_block_can_cross_midnight(): void
    {
        $reservation = $this->reservation(startsAt: self::at('2030-03-31 23:30'));

        $this->assertEquals(self::at('2030-04-01 00:15'), $reservation->interval->end);
        $this->assertEquals(self::at('2030-04-01 00:20'), $reservation->blockedUntil);
    }

    /** The creation is the first entry of the history: no previous status, the author and the moment (RE-09). */
    public function test_the_creation_leaves_its_entry_in_the_history(): void
    {
        $author = Author::person(new UserId(self::USER_ID));
        $reservation = $this->reservation(author: $author, at: self::at('2030-03-01 08:15'));

        $this->assertCount(1, $reservation->pendingChanges);
        $this->assertNull($reservation->pendingChanges[0]->from);
        $this->assertSame(ReservationStatus::Temporary, $reservation->pendingChanges[0]->to);
        $this->assertSame(self::USER_ID, $reservation->pendingChanges[0]->author->user?->value);
        $this->assertEquals(self::at('2030-03-01 08:15'), $reservation->pendingChanges[0]->at);
    }

    /** A price of zero is not a price (SV-01). */
    public function test_a_free_reservation_is_rejected(): void
    {
        $this->expectException(InvalidValueException::class);

        $this->reservation(price: Money::zero());
    }

    /** A negative cancellation deadline makes no sense (CA-01). */
    public function test_a_negative_cancellation_deadline_is_rejected(): void
    {
        $this->expectException(InvalidValueException::class);

        $this->reservation(cancellationDeadlineHours: -1);
    }

    /** Every change the diagram of RE-04 allows is applied and takes the status, the author and the moment given. */
    #[DataProvider('allowedTransitions')]
    public function test_an_allowed_transition_changes_the_status_and_records_it(ReservationStatus $from, string $action, ReservationStatus $to): void
    {
        $reservation = $this->reservationIn($from);
        $author = Author::person(new UserId(self::USER_ID));
        $at = self::at('2030-03-01 08:05');

        $reservation->{$action}($at, $author);

        $this->assertSame($to, $reservation->status);
        $this->assertCount(1, $reservation->pendingChanges);
        $this->assertSame($from, $reservation->pendingChanges[0]->from);
        $this->assertSame($to, $reservation->pendingChanges[0]->to);
        $this->assertSame(self::USER_ID, $reservation->pendingChanges[0]->author->user?->value);
        $this->assertEquals($at, $reservation->pendingChanges[0]->at);
    }

    /** Every change the diagram does not have, including all the ones out of a final status, is rejected and leaves the reservation as it was. */
    #[DataProvider('forbiddenTransitions')]
    public function test_a_forbidden_transition_is_rejected_and_changes_nothing(ReservationStatus $from, string $action): void
    {
        $reservation = $this->reservationIn($from);

        try {
            $reservation->{$action}(self::at('2030-03-02 09:30'), Author::system());
            $this->fail("{$from->value} accepted {$action}.");
        } catch (TransitionNotAllowedException $exception) {
            $this->assertSame('reservations.transition_not_allowed', $exception->translationKey());
        }

        $this->assertSame($from, $reservation->status);
        $this->assertSame([], $reservation->pendingChanges);
    }

    /** A final status never changes, whatever is asked (RE-04). */
    #[DataProvider('finalStatuses')]
    public function test_a_final_status_is_immutable(ReservationStatus $final): void
    {
        $this->assertTrue($final->isFinal());
        $this->assertSame([], $final->next());

        foreach (ReservationStatus::cases() as $target) {
            $this->assertFalse($final->canChangeTo($target));
        }
    }

    /** The statuses that still can change are not final. */
    public function test_temporary_and_confirmed_are_not_final(): void
    {
        $this->assertFalse(ReservationStatus::Temporary->isFinal());
        $this->assertFalse(ReservationStatus::Confirmed->isFinal());
    }

    /** The system can be the author, for the changes nobody asks for (RE-09). */
    public function test_the_system_can_be_the_author(): void
    {
        $reservation = $this->reservation();

        $reservation->expire(self::at('2030-03-01 08:30'), Author::system());

        $this->assertTrue($reservation->pendingChanges[1]->author->isSystem());
        $this->assertNull($reservation->pendingChanges[1]->author->user);
    }

    /** The history keeps one entry per change, in order, with the status each one left. */
    public function test_the_history_accumulates_in_order(): void
    {
        $reservation = $this->reservation(at: self::at('2030-03-01 08:00'));
        $person = Author::person(new UserId(self::USER_ID));

        $reservation->confirm(self::at('2030-03-01 08:05'), Author::system());
        $reservation->complete(self::at('2030-03-04 10:50'), $person);

        $this->assertSame(
            [[null, 'temporary'], ['temporary', 'confirmed'], ['confirmed', 'completed']],
            array_map(fn ($change): array => [$change->from?->value, $change->to->value], $reservation->pendingChanges),
        );
        $this->assertTrue($reservation->pendingChanges[1]->author->isSystem());
        $this->assertSame(self::USER_ID, $reservation->pendingChanges[2]->author->user?->value);
    }

    /** Once the repository saved the changes, none is pending, and a reservation read from the database has none. */
    public function test_saved_and_reconstituted_reservations_have_nothing_pending(): void
    {
        $reservation = $this->reservation();
        $reservation->markSaved();

        $this->assertSame([], $reservation->pendingChanges);

        $stored = Reservation::reconstitute(
            new ReservationId(self::RESERVATION_ID),
            new CollaboratorId(self::COLLABORATOR_ID),
            new ServiceId(self::SERVICE_ID),
            new CustomerMembershipId(self::CUSTOMER_ID),
            TimeInterval::between(self::at('2030-03-04 10:00'), self::at('2030-03-04 10:45')),
            self::at('2030-03-04 10:50'),
            Money::of(8500),
            Duration::ofMinutes(45),
            4,
            1,
            ReservationStatus::Confirmed,
            null,
        );

        $this->assertSame(ReservationStatus::Confirmed, $stored->status);
        $this->assertSame(1, $stored->rescheduleCount);
        $this->assertSame([], $stored->pendingChanges);
    }

    /** A customer's cancellation is on time up to the deadline copied into the reservation (4 hours before 10:00), and late after it (CA-01). */
    public function test_the_cancellation_of_the_customer_is_classified_by_the_deadline(): void
    {
        $reservation = $this->reservationIn(ReservationStatus::CancelledByCustomer);

        $this->assertSame(CancellationClass::OnTime, $reservation->classifyCancellation(self::at('2030-03-01 08:00')));
        $this->assertSame(CancellationClass::OnTime, $reservation->classifyCancellation(self::at('2030-03-04 06:00')));
        $this->assertSame(CancellationClass::Late, $reservation->classifyCancellation(self::at('2030-03-04 06:00:01')));
        $this->assertSame(CancellationClass::Late, $reservation->classifyCancellation(self::at('2030-03-04 11:00')));
    }

    /** A cancellation of the barbershop is its own class, whenever it happens (CA-04). */
    public function test_the_cancellation_of_the_barbershop_is_its_own_class(): void
    {
        $reservation = $this->reservationIn(ReservationStatus::CancelledByBarbershop);

        $this->assertSame(CancellationClass::ByBarbershop, $reservation->classifyCancellation(self::at('2030-03-04 09:59')));
    }

    /** Only a cancelled reservation has a cancellation class. */
    public function test_a_reservation_that_is_not_cancelled_has_no_class(): void
    {
        $this->expectException(LogicException::class);

        $this->reservationIn(ReservationStatus::Confirmed)->classifyCancellation(self::at('2030-03-02 09:00'));
    }

    /**
     * @return iterable<string, array{ReservationStatus, string, ReservationStatus}>
     */
    public static function allowedTransitions(): iterable
    {
        yield 'temporary to confirmed' => [ReservationStatus::Temporary, 'confirm', ReservationStatus::Confirmed];
        yield 'temporary to expired' => [ReservationStatus::Temporary, 'expire', ReservationStatus::Expired];
        yield 'temporary to cancelled by the customer' => [ReservationStatus::Temporary, 'cancelByCustomer', ReservationStatus::CancelledByCustomer];
        yield 'confirmed to completed' => [ReservationStatus::Confirmed, 'complete', ReservationStatus::Completed];
        yield 'confirmed to no show' => [ReservationStatus::Confirmed, 'markNoShow', ReservationStatus::NoShow];
        yield 'confirmed to cancelled by the customer' => [ReservationStatus::Confirmed, 'cancelByCustomer', ReservationStatus::CancelledByCustomer];
        yield 'confirmed to cancelled by the barbershop' => [ReservationStatus::Confirmed, 'cancelByBarbershop', ReservationStatus::CancelledByBarbershop];
    }

    /**
     * Every pair of status and action that is not in the diagram: the whole matrix minus the seven allowed changes.
     *
     * @return iterable<string, array{ReservationStatus, string}>
     */
    public static function forbiddenTransitions(): iterable
    {
        $targets = [
            'confirm' => ReservationStatus::Confirmed,
            'expire' => ReservationStatus::Expired,
            'complete' => ReservationStatus::Completed,
            'markNoShow' => ReservationStatus::NoShow,
            'cancelByCustomer' => ReservationStatus::CancelledByCustomer,
            'cancelByBarbershop' => ReservationStatus::CancelledByBarbershop,
        ];

        foreach (ReservationStatus::cases() as $from) {
            foreach ($targets as $action => $target) {
                if (! $from->canChangeTo($target)) {
                    yield "{$from->value} cannot {$action}" => [$from, $action];
                }
            }
        }
    }

    /**
     * @return iterable<string, array{ReservationStatus}>
     */
    public static function finalStatuses(): iterable
    {
        foreach ([ReservationStatus::Completed, ReservationStatus::NoShow, ReservationStatus::CancelledByCustomer, ReservationStatus::CancelledByBarbershop, ReservationStatus::Expired] as $status) {
            yield $status->value => [$status];
        }
    }

    /** The matrix above is the one of RE-04: seven changes allowed out of the forty-two there are, written down here independently. */
    public function test_the_diagram_has_exactly_the_changes_of_re_04(): void
    {
        $allowed = [];

        foreach (ReservationStatus::cases() as $from) {
            foreach (ReservationStatus::cases() as $to) {
                if ($from->canChangeTo($to)) {
                    $allowed[] = "{$from->value}>{$to->value}";
                }
            }
        }

        $this->assertEqualsCanonicalizing([
            'temporary>confirmed',
            'temporary>expired',
            'temporary>cancelled_by_customer',
            'confirmed>completed',
            'confirmed>no_show',
            'confirmed>cancelled_by_customer',
            'confirmed>cancelled_by_barbershop',
        ], $allowed);
        $this->assertCount(35, iterator_to_array(self::forbiddenTransitions()));
    }

    /** Rescheduling moves the start, the end and the cleaning, keeps the copies and counts one more reschedule (RF-15, SV-03, DI-01). */
    public function test_rescheduling_moves_the_space_and_keeps_the_copies(): void
    {
        $reservation = $this->reservationIn(ReservationStatus::Confirmed);

        $reservation->reschedule(new CollaboratorId(self::OTHER_COLLABORATOR_ID), self::at('2030-03-04 14:00'), false, self::at('2030-03-01 08:00'), Author::person(new UserId(self::USER_ID)));

        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame(self::OTHER_COLLABORATOR_ID, $reservation->collaboratorId->value);
        $this->assertEquals(self::at('2030-03-04 14:00'), $reservation->interval->start);
        $this->assertEquals(self::at('2030-03-04 14:45'), $reservation->interval->end);
        $this->assertEquals(self::at('2030-03-04 14:50'), $reservation->blockedUntil);
        $this->assertSame(1, $reservation->rescheduleCount);
        $this->assertSame(8500, $reservation->price->colones);
        $this->assertSame(45, $reservation->duration->minutes);
        $this->assertSame(4, $reservation->cancellationDeadlineHours);
    }

    /** The move leaves one confirmed-to-confirmed entry with its author and date (RE-09). */
    public function test_rescheduling_leaves_a_confirmed_to_confirmed_entry(): void
    {
        $reservation = $this->reservationIn(ReservationStatus::Confirmed);
        $author = Author::person(new UserId(self::USER_ID));

        $reservation->reschedule(new CollaboratorId(self::COLLABORATOR_ID), self::at('2030-03-04 11:00'), false, self::at('2030-03-01 09:00'), $author);

        $this->assertCount(1, $reservation->pendingChanges);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->pendingChanges[0]->from);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->pendingChanges[0]->to);
        $this->assertSame($author, $reservation->pendingChanges[0]->author);
        $this->assertEquals(self::at('2030-03-01 09:00'), $reservation->pendingChanges[0]->at);
    }

    /** The customer measures the deadline against the original start: 4 hours before it still works, a minute later does not (CA-01). */
    public function test_the_customer_deadline_is_measured_against_the_original_start(): void
    {
        $reservation = $this->reservationIn(ReservationStatus::Confirmed);
        $reservation->reschedule(new CollaboratorId(self::COLLABORATOR_ID), self::at('2030-03-04 16:00'), false, self::at('2030-03-04 06:00'), Author::system());

        $late = $this->reservationIn(ReservationStatus::Confirmed);

        try {
            $late->reschedule(new CollaboratorId(self::COLLABORATOR_ID), self::at('2030-03-04 16:00'), false, self::at('2030-03-04 06:01'), Author::system());
            $this->fail('The deadline had passed.');
        } catch (RescheduleDeadlineExpiredException) {
            $this->assertSame(0, $late->rescheduleCount);
            $this->assertEquals(self::at('2030-03-04 10:00'), $late->interval->start);
            $this->assertSame([], $late->pendingChanges);
        }
    }

    /** The owner is not bound by the deadline (CA-07). */
    public function test_the_owner_has_no_deadline(): void
    {
        $reservation = $this->reservationIn(ReservationStatus::Confirmed);

        $reservation->reschedule(new CollaboratorId(self::COLLABORATOR_ID), self::at('2030-03-04 16:00'), true, self::at('2030-03-04 09:59'), Author::system());

        $this->assertSame(1, $reservation->rescheduleCount);
    }

    /** A reservation is rescheduled twice at most (CA-05). */
    public function test_the_third_reschedule_is_rejected(): void
    {
        $reservation = $this->reservationIn(ReservationStatus::Confirmed);
        $at = self::at('2030-03-01 08:00');

        $reservation->reschedule(new CollaboratorId(self::COLLABORATOR_ID), self::at('2030-03-04 11:00'), false, $at, Author::system());
        $reservation->reschedule(new CollaboratorId(self::COLLABORATOR_ID), self::at('2030-03-04 12:00'), false, $at, Author::system());

        $this->expectException(RescheduleLimitReachedException::class);

        try {
            $reservation->reschedule(new CollaboratorId(self::COLLABORATOR_ID), self::at('2030-03-04 13:00'), true, $at, Author::system());
        } finally {
            $this->assertSame(2, $reservation->rescheduleCount);
            $this->assertEquals(self::at('2030-03-04 12:00'), $reservation->interval->start);
        }
    }

    /** Only a confirmed reservation can be rescheduled (RF-15, RE-04). */
    #[DataProvider('notConfirmedStatuses')]
    public function test_only_a_confirmed_reservation_can_be_rescheduled(ReservationStatus $status): void
    {
        $reservation = $this->reservationIn($status);

        $this->expectException(TransitionNotAllowedException::class);

        $reservation->reschedule(new CollaboratorId(self::COLLABORATOR_ID), self::at('2030-03-04 11:00'), true, self::at('2030-03-01 08:00'), Author::system());
    }

    /** @return iterable<string, array{ReservationStatus}> */
    public static function notConfirmedStatuses(): iterable
    {
        foreach (ReservationStatus::cases() as $status) {
            if ($status !== ReservationStatus::Confirmed) {
                yield $status->value => [$status];
            }
        }
    }

    /** A reservation in the status, reached through the allowed changes and without pending history. */
    private function reservationIn(ReservationStatus $status): Reservation
    {
        $reservation = $this->reservation();
        $at = self::at('2030-03-01 08:05');
        $system = Author::system();

        $steps = match ($status) {
            ReservationStatus::Temporary => [],
            ReservationStatus::Confirmed => ['confirm'],
            ReservationStatus::Completed => ['confirm', 'complete'],
            ReservationStatus::NoShow => ['confirm', 'markNoShow'],
            ReservationStatus::CancelledByCustomer => ['cancelByCustomer'],
            ReservationStatus::CancelledByBarbershop => ['confirm', 'cancelByBarbershop'],
            ReservationStatus::Expired => ['expire'],
        };

        foreach ($steps as $step) {
            $reservation->{$step}($at, $system);
        }

        $reservation->markSaved();

        return $reservation;
    }

    /** A reservation of 45 minutes at 10:00 with Daniela. */
    private function reservation(
        ?DateTimeImmutable $startsAt = null,
        ?Money $price = null,
        int $cancellationDeadlineHours = 4,
        ?Author $author = null,
        ?DateTimeImmutable $at = null,
    ): Reservation {
        return Reservation::create(
            new ReservationId(self::RESERVATION_ID),
            new CollaboratorId(self::COLLABORATOR_ID),
            new ServiceId(self::SERVICE_ID),
            new CustomerMembershipId(self::CUSTOMER_ID),
            $startsAt ?? self::at('2030-03-04 10:00'),
            $price ?? Money::of(8500),
            Duration::ofMinutes(45),
            $cancellationDeadlineHours,
            $author ?? Author::system(),
            $at ?? self::at('2030-03-01 08:00'),
        );
    }

    /** An instant in the time zone of Costa Rica. */
    private static function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }
}
