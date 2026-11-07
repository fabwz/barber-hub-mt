<?php

declare(strict_types=1);

namespace Tests\Unit\Reservations\Reservations\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Payments\Payments\Application\DTOs\ChargePolicy;
use Src\Reservations\Reservations\Application\QuotePayment;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\ServiceId;
use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\Percentage;
use Src\Shared\Domain\ValueObjects\UserId;
use Tests\Support\Reservations\InMemoryReservationRepository;
use Tests\Support\Reservations\PolicyPaymentAmounts;

/** What a reservation owes now follows the policy of the barbershop through the very calculation of the charge, and the balance is the rest (RF-20, PA-01, PA-06, RE-05). */
final class QuotePaymentTest extends TestCase
{
    private const string ANA_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    private const string ANA_ROLE = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d71';

    private const string DANIELA = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d55';

    private const string SERVICE = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d33';

    /** @return array<string, array{ChargePolicy, int, int, int}> */
    public static function policies(): array
    {
        return [
            'no payment owes nothing' => [ChargePolicy::none(), 8500, 0, 8500],
            'a deposit that divides exactly' => [ChargePolicy::deposit(Percentage::of(30)), 8500, 2550, 5950],
            'a deposit rounds up to the colon' => [ChargePolicy::deposit(Percentage::of(33)), 5001, 1651, 3350],
            'the full payment is the price' => [ChargePolicy::full(), 8500, 8500, 0],
        ];
    }

    /** Each mode of the policy gives its amount over a temporary reservation, with the balance and the deadline. */
    #[DataProvider('policies')]
    public function test_a_temporary_reservation_owes_what_the_policy_charges(ChargePolicy $policy, int $price, int $amount, int $balance): void
    {
        $reservation = $this->temporary($price);

        $due = $this->quote($policy)->one($reservation, $this->at('08:05'));

        $this->assertSame($amount, $due->amount);
        $this->assertSame($balance, $due->balanceAtShop);
        $this->assertEquals($amount > 0 ? $reservation->expiresAt : null, $due->expiresAt);
    }

    /** A confirmed reservation, like one whose payment was approved, owes nothing and has no deadline. */
    public function test_a_confirmed_reservation_owes_nothing(): void
    {
        $reservation = $this->temporary(8500);
        $reservation->confirm($this->at('08:03'), Author::system());

        $due = $this->quote(ChargePolicy::deposit(Percentage::of(30)))->one($reservation, $this->at('08:05'));

        $this->assertSame(0, $due->amount);
        $this->assertSame(8500, $due->balanceAtShop);
        $this->assertNull($due->expiresAt);
    }

    /** A temporary reservation past its window reads as expired, so it owes nothing. */
    public function test_a_reservation_past_its_window_owes_nothing(): void
    {
        $due = $this->quote(ChargePolicy::full())->one($this->temporary(8500), $this->at('08:10'));

        $this->assertSame(0, $due->amount);
        $this->assertNull($due->expiresAt);
    }

    private function quote(ChargePolicy $policy): QuotePayment
    {
        return new QuotePayment(new PolicyPaymentAmounts($policy));
    }

    private function temporary(int $price): Reservation
    {
        return Reservation::create(
            (new InMemoryReservationRepository)->nextId(),
            new CollaboratorId(self::DANIELA),
            new ServiceId(self::SERVICE),
            new CustomerMembershipId(self::ANA_ROLE),
            new DateTimeImmutable('2030-03-05 10:00', new DateTimeZone('America/Costa_Rica')),
            Money::of($price),
            Duration::ofMinutes(45),
            24,
            Author::person(new UserId(self::ANA_USER)),
            $this->at('08:00'),
        );
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2030-03-01 {$time}", new DateTimeZone('America/Costa_Rica'));
    }
}
