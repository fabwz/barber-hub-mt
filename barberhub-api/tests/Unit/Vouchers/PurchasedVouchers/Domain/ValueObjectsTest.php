<?php

declare(strict_types=1);

namespace Tests\Unit\Vouchers\PurchasedVouchers\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\VoucherMovement;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\BalanceAboveTotalException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\InsufficientBalanceException;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\SessionBalance;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\VoucherMovementType;
use Src\Vouchers\Shared\Domain\ValueObjects\SessionCount;
use Src\Vouchers\Shared\Domain\ValueObjects\ValidityDays;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherName;
use Tests\Support\Vouchers\VoucherFactory;

/** The value objects of the vouchers: the numbers that cannot be wrong and the record that only grows (BO-01, BO-03, BO-09). */
final class ValueObjectsTest extends TestCase
{
    /** A balance cannot be built negative, and it cannot be taken below zero. */
    public function test_a_balance_is_never_negative(): void
    {
        $this->assertSame(0, SessionBalance::of(0)->value);
        $this->assertTrue(SessionBalance::zero()->isZero());

        $this->expectException(InvalidValueException::class);

        SessionBalance::of(-1);
    }

    /** Taking a session from a balance of zero is the stable "saldo-insuficiente" error. */
    public function test_decrementing_zero_is_refused(): void
    {
        $this->expectException(InsufficientBalanceException::class);

        SessionBalance::zero()->decrement();
    }

    /** Taking and giving back move the balance by one and stop at the total. */
    public function test_a_balance_moves_by_one_between_zero_and_the_total(): void
    {
        $total = SessionCount::of(2);

        $this->assertSame(1, SessionBalance::of(2)->decrement()->value);
        $this->assertSame(2, SessionBalance::of(1)->increment($total)->value);

        $this->expectException(BalanceAboveTotalException::class);

        SessionBalance::of(2)->increment($total);
    }

    /** @return array<string, array{int}> */
    public static function invalidCounts(): array
    {
        return ['zero' => [0], 'negative' => [-3], 'beyond the column' => [32768]];
    }

    /** A voucher with 0 sessions is rejected (BO-01, RF-26). */
    #[DataProvider('invalidCounts')]
    public function test_a_session_count_must_be_positive_and_fit(int $value): void
    {
        $this->expectException(InvalidValueException::class);

        SessionCount::of($value);
    }

    /** The validity is a positive number of days and it ends that many days after the activation (BO-01, BO-07). */
    #[DataProvider('invalidCounts')]
    public function test_the_validity_must_be_positive_and_fit(int $value): void
    {
        $this->expectException(InvalidValueException::class);

        ValidityDays::of($value);
    }

    /** Ninety days from the activation. */
    public function test_the_validity_ends_the_given_days_after_the_activation(): void
    {
        $this->assertEquals(
            new DateTimeImmutable('2030-05-30 08:00:00-06:00'),
            ValidityDays::of(90)->endingFrom(new DateTimeImmutable('2030-03-01 08:00:00-06:00')),
        );
    }

    /** The name is trimmed; a blank one or one over 120 characters is rejected. */
    public function test_the_name_is_trimmed_and_limited(): void
    {
        $this->assertSame('Bono 5 cortes', VoucherName::of('  Bono 5 cortes ')->value);
        $this->assertSame(VoucherName::MAX_LENGTH, mb_strlen(VoucherName::of(str_repeat('ñ', VoucherName::MAX_LENGTH))->value));

        foreach (['', '   ', str_repeat('a', VoucherName::MAX_LENGTH + 1)] as $invalid) {
            try {
                VoucherName::of($invalid);
                $this->fail('The name was invalid.');
            } catch (InvalidValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** Each kind of entry has its own amount and only the purchase has no reservation (BO-09). */
    public function test_each_movement_has_its_amount_and_reservation(): void
    {
        $now = VoucherFactory::now();
        $reservation = VoucherFactory::reservation();

        $this->assertSame(5, VoucherMovement::purchase(SessionCount::of(5), $now)->sessionsDelta);
        $this->assertSame(-1, VoucherMovement::hold($reservation, $now)->sessionsDelta);
        $this->assertSame(1, VoucherMovement::release($reservation, $now)->sessionsDelta);
        $this->assertSame(0, VoucherMovement::consume($reservation, $now)->sessionsDelta);
        $this->assertSame(
            [false, true, true, true],
            array_map(fn (VoucherMovementType $type): bool => $type->hasReservation(), [VoucherMovementType::Purchase, VoucherMovementType::Hold, VoucherMovementType::Release, VoucherMovementType::Consume]),
        );
    }

    /** @return array<string, array{VoucherMovementType, int, bool}> */
    public static function inconsistentMovements(): array
    {
        return [
            'a hold that gives sessions' => [VoucherMovementType::Hold, 1, true],
            'a release that takes two' => [VoucherMovementType::Release, 2, true],
            'a consume that changes the balance' => [VoucherMovementType::Consume, 1, true],
            'a purchase that takes sessions' => [VoucherMovementType::Purchase, -5, false],
            'a purchase of nothing' => [VoucherMovementType::Purchase, 0, false],
            'a purchase with a reservation' => [VoucherMovementType::Purchase, 5, true],
            'a hold without a reservation' => [VoucherMovementType::Hold, -1, false],
        ];
    }

    /** An entry whose kind, amount and reservation disagree is not rebuilt, as the table does not accept it either. */
    #[DataProvider('inconsistentMovements')]
    public function test_an_inconsistent_movement_is_refused(VoucherMovementType $type, int $delta, bool $withReservation): void
    {
        $this->expectException(InvalidValueException::class);

        VoucherMovement::reconstitute($type, $delta, $withReservation ? VoucherFactory::reservation() : null, VoucherFactory::now());
    }

    /** The record adds up to the balance. */
    public function test_the_record_adds_up(): void
    {
        $now = VoucherFactory::now();
        $reservation = VoucherFactory::reservation();

        $this->assertSame(0, VoucherMovement::balanceOf([]));
        $this->assertSame(4, VoucherMovement::balanceOf([
            VoucherMovement::purchase(SessionCount::of(5), $now),
            VoucherMovement::hold($reservation, $now),
            VoucherMovement::consume($reservation, $now),
        ]));
    }
}
