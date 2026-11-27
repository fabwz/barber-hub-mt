<?php

declare(strict_types=1);

namespace Tests\Unit\Reservations\Reservations\Domain;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PHPUnit\Framework\TestCase;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\ValueObjects\AssistedClientId;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\CreatorMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Domain\ValueObjects\ServiceId;
use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Shared\Domain\ValueObjects\UserId;

/** The reservation the owner registers for a person without an account (RF-12, CL-06, RE-07): its customer is an assisted client and never an account too. */
final class AssistedReservationTest extends TestCase
{
    private const string OWNER_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d05';

    private static function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }

    private function assisted(): Reservation
    {
        return Reservation::createAssisted(
            new ReservationId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d01'),
            new CollaboratorId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d02'),
            new ServiceId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d03'),
            new AssistedClientId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d07'),
            new CreatorMembershipId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d08'),
            self::at('2030-03-04 10:00'),
            Money::of(8500),
            Duration::ofMinutes(45),
            24,
            Author::person(new UserId(self::OWNER_USER)),
            self::at('2030-03-01 08:00'),
        );
    }

    /** It belongs to the assisted client and to no customer role, and records who registered it. */
    public function test_an_assisted_reservation_belongs_to_the_assisted_client_only(): void
    {
        $reservation = $this->assisted();

        $this->assertTrue($reservation->isAssisted());
        $this->assertNull($reservation->customerMembershipId);
        $this->assertSame('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d07', $reservation->assistedClientId?->value);
        $this->assertSame('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d08', $reservation->createdByMembershipId?->value);
    }

    /** It is born like any other and the owner confirms it at once; the history keeps both entries by the owner (RE-07, RE-09). */
    public function test_it_is_confirmed_at_once_with_the_owner_as_author(): void
    {
        $reservation = $this->assisted();
        $author = Author::person(new UserId(self::OWNER_USER));

        $this->assertSame(ReservationStatus::Temporary, $reservation->status);

        $reservation->confirm(self::at('2030-03-01 08:00'), $author);

        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertNull($reservation->expiresAt);
        $this->assertCount(2, $reservation->pendingChanges);
        $this->assertSame(self::OWNER_USER, $reservation->pendingChanges[1]->author->user?->value);
    }

    /** It holds the same space as an online reservation: the service plus five minutes of cleaning (DI-01). */
    public function test_it_holds_the_service_and_the_cleaning(): void
    {
        $reservation = $this->assisted();

        $this->assertEquals(TimeInterval::between(self::at('2030-03-04 10:00'), self::at('2030-03-04 10:45')), $reservation->interval);
        $this->assertEquals(self::at('2030-03-04 10:50'), $reservation->blockedUntil);
    }

    /** A reservation of an account stays as it was: no assisted client. */
    public function test_an_online_reservation_has_no_assisted_client(): void
    {
        $reservation = Reservation::create(
            new ReservationId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d01'),
            new CollaboratorId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d02'),
            new ServiceId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d03'),
            new CustomerMembershipId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d04'),
            self::at('2030-03-04 10:00'),
            Money::of(8500),
            Duration::ofMinutes(45),
            24,
            Author::system(),
            self::at('2030-03-01 08:00'),
        );

        $this->assertFalse($reservation->isAssisted());
        $this->assertNull($reservation->assistedClientId);
        $this->assertNull($reservation->createdByMembershipId);
    }

    /** A stored reservation of both customers or of none is a corrupt row, never a valid state. */
    public function test_a_reservation_of_both_customers_or_of_none_cannot_be_rebuilt(): void
    {
        $rebuild = fn (?CustomerMembershipId $customer, ?AssistedClientId $assisted): Reservation => Reservation::reconstitute(
            new ReservationId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d01'),
            new CollaboratorId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d02'),
            new ServiceId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d03'),
            $customer,
            TimeInterval::between(self::at('2030-03-04 10:00'), self::at('2030-03-04 10:45')),
            self::at('2030-03-04 10:50'),
            Money::of(8500),
            Duration::ofMinutes(45),
            24,
            0,
            ReservationStatus::Confirmed,
            null,
            null,
            $assisted,
        );
        $customer = new CustomerMembershipId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d04');
        $assisted = new AssistedClientId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d07');

        $this->assertTrue($rebuild(null, $assisted)->isAssisted());
        $this->assertFalse($rebuild($customer, null)->isAssisted());

        foreach ([[$customer, $assisted], [null, null]] as [$a, $b]) {
            try {
                $rebuild($a, $b);
                $this->fail('A reservation without exactly one customer was rebuilt.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
