<?php

declare(strict_types=1);

namespace Tests\Unit\Vouchers\PurchasedVouchers\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\PurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\VoucherMovement;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\BalanceAboveTotalException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\InsufficientBalanceException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherAlreadyActiveException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherExpiredException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotActiveException;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\SessionBalance;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\VoucherMovementType;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\VoucherStatus;
use Tests\Support\Vouchers\VoucherFactory;

/** The rules of the purchased voucher, without a database: BO-02, BO-03, BO-04, BO-05, BO-06, BO-07 and BO-09. */
final class PurchasedVoucherTest extends TestCase
{
    /** A voucher that was just bought has no sessions, no dates and an empty record until its payment is confirmed (BO-02). */
    public function test_a_new_voucher_is_pending_without_sessions_or_dates(): void
    {
        $voucher = VoucherFactory::pending();

        $this->assertSame(VoucherStatus::Pending, $voucher->status);
        $this->assertSame(0, $voucher->balance->value);
        $this->assertNull($voucher->activatedAt);
        $this->assertNull($voucher->expiresAt);
        $this->assertSame([], $voucher->pullMovements());
    }

    /** It keeps what the definition said when it was bought, even if the definition changes afterwards. */
    public function test_a_new_voucher_keeps_the_values_of_the_purchase(): void
    {
        $voucher = VoucherFactory::pending(sessions: 5, price: 30000, validityDays: 90);

        $this->assertSame('Bono 5 cortes', $voucher->name->value);
        $this->assertSame(30000, $voucher->price->colones);
        $this->assertSame(5, $voucher->sessionsCount->value);
        $this->assertSame(90, $voucher->validityDays->value);
    }

    /** A voucher without a positive price is not sold. */
    public function test_a_voucher_needs_a_positive_price(): void
    {
        $this->expectException(InvalidValueException::class);

        VoucherFactory::pending(price: 0);
    }

    /** Paying gives the 5 sessions and a validity of 90 days that count from that moment, and writes the purchase in the record (RF-27, BO-09). */
    public function test_activating_gives_the_sessions_and_the_validity_from_that_moment(): void
    {
        $paidAt = new DateTimeImmutable('2030-03-02 10:30:00-06:00');
        $voucher = VoucherFactory::pending();

        $voucher->activate($paidAt);

        $this->assertSame(VoucherStatus::Active, $voucher->status);
        $this->assertSame(5, $voucher->balance->value);
        $this->assertEquals($paidAt, $voucher->activatedAt);
        $this->assertEquals(new DateTimeImmutable('2030-05-31 10:30:00-06:00'), $voucher->expiresAt);
        $movements = $voucher->pullMovements();
        $this->assertCount(1, $movements);
        $this->assertSame(VoucherMovementType::Purchase, $movements[0]->type);
        $this->assertSame(5, $movements[0]->sessionsDelta);
        $this->assertNull($movements[0]->reservationId);
    }

    /** A second confirmation of the same payment cannot give the sessions again (BO-02). */
    public function test_activating_twice_is_refused_and_gives_nothing(): void
    {
        $voucher = VoucherFactory::active();
        $voucher->pullMovements();

        try {
            $voucher->activate(VoucherFactory::now());
            $this->fail('A voucher cannot be activated twice.');
        } catch (VoucherAlreadyActiveException) {
            $this->assertSame(5, $voucher->balance->value);
            $this->assertSame([], $voucher->pullMovements());
        }
    }

    /** Setting a session aside takes one and records it with its reservation (BO-04, BO-09). */
    public function test_holding_takes_one_session_and_records_it(): void
    {
        $voucher = VoucherFactory::active();
        $voucher->pullMovements();

        $voucher->hold(VoucherFactory::reservation(), VoucherFactory::now());

        $this->assertSame(4, $voucher->balance->value);
        $movements = $voucher->pullMovements();
        $this->assertCount(1, $movements);
        $this->assertSame(VoucherMovementType::Hold, $movements[0]->type);
        $this->assertSame(-1, $movements[0]->sessionsDelta);
        $this->assertSame(VoucherFactory::RESERVATION, $movements[0]->reservationId?->value);
    }

    /** The balance never goes below zero: the sixth session of a voucher of five is refused with the stable error (BO-03). */
    public function test_the_balance_never_goes_negative(): void
    {
        $voucher = VoucherFactory::active(sessions: 1);
        $voucher->hold(VoucherFactory::reservation(), VoucherFactory::now());
        $voucher->pullMovements();

        try {
            $voucher->hold(VoucherFactory::reservation('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0b01'), VoucherFactory::now());
            $this->fail('The last session was already taken.');
        } catch (InsufficientBalanceException $exception) {
            $this->assertSame('saldo-insuficiente', $exception->rule());
            $this->assertSame(0, $voucher->balance->value);
            $this->assertSame([], $voucher->pullMovements());
        }
    }

    /** A voucher that is not paid has no sessions to hold. */
    public function test_a_pending_voucher_cannot_hold_release_or_consume(): void
    {
        foreach (['hold', 'release', 'consume'] as $operation) {
            $voucher = VoucherFactory::pending();

            try {
                $voucher->{$operation}(VoucherFactory::reservation(), VoucherFactory::now());
                $this->fail("A pending voucher cannot {$operation}.");
            } catch (VoucherNotActiveException) {
                $this->assertSame([], $voucher->pullMovements());
            }
        }
    }

    /** The validity ends exactly at the expiry: one second before it the voucher holds, at that instant it does not (BO-06, BO-07). */
    public function test_the_validity_ends_at_the_expiry_instant(): void
    {
        $voucher = VoucherFactory::active();
        $expiresAt = $voucher->expiresAt;
        $this->assertNotNull($expiresAt);

        $voucher->hold(VoucherFactory::reservation(), $expiresAt->modify('-1 second'));
        $this->assertSame(4, $voucher->balance->value);

        try {
            $voucher->hold(VoucherFactory::reservation('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0b02'), $expiresAt);
            $this->fail('The voucher was expired.');
        } catch (VoucherExpiredException $exception) {
            $this->assertSame('bono-vencido', $exception->rule());
            $this->assertSame(4, $voucher->balance->value);
        }
    }

    /** An expired voucher with no sessions says it expired, not that the balance is short: the validity is checked first. */
    public function test_expiry_is_reported_before_the_lack_of_sessions(): void
    {
        $voucher = VoucherFactory::active(sessions: 1);
        $voucher->hold(VoucherFactory::reservation(), VoucherFactory::now());
        $expiresAt = $voucher->expiresAt;
        $this->assertNotNull($expiresAt);

        $this->expectException(VoucherExpiredException::class);

        $voucher->hold(VoucherFactory::reservation('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0b03'), $expiresAt->modify('+1 day'));
    }

    /** Giving a session back adds one and records it; the voucher cannot end with more sessions than it was bought with (BO-05). */
    public function test_releasing_gives_the_session_back_up_to_the_total(): void
    {
        $voucher = VoucherFactory::active();
        $voucher->hold(VoucherFactory::reservation(), VoucherFactory::now());
        $voucher->pullMovements();

        $voucher->release(VoucherFactory::reservation(), VoucherFactory::now());

        $this->assertSame(5, $voucher->balance->value);
        $movements = $voucher->pullMovements();
        $this->assertSame(VoucherMovementType::Release, $movements[0]->type);
        $this->assertSame(1, $movements[0]->sessionsDelta);

        $this->expectException(BalanceAboveTotalException::class);

        $voucher->release(VoucherFactory::reservation(), VoucherFactory::now());
    }

    /** The session of a missed or late-cancelled reservation was taken when it was confirmed: the entry says so and the balance stays (BO-05). */
    public function test_consuming_records_the_use_without_changing_the_balance(): void
    {
        $voucher = VoucherFactory::active();
        $voucher->hold(VoucherFactory::reservation(), VoucherFactory::now());
        $voucher->pullMovements();

        $voucher->consume(VoucherFactory::reservation(), VoucherFactory::now());

        $this->assertSame(4, $voucher->balance->value);
        $movements = $voucher->pullMovements();
        $this->assertSame(VoucherMovementType::Consume, $movements[0]->type);
        $this->assertSame(0, $movements[0]->sessionsDelta);
    }

    /** A session is given back to a voucher that already expired; what it shows is still zero (BO-05, BO-07). */
    public function test_a_release_after_the_expiry_is_kept_but_the_voucher_shows_zero(): void
    {
        $voucher = VoucherFactory::active();
        $voucher->hold(VoucherFactory::reservation(), VoucherFactory::now());
        $expiresAt = $voucher->expiresAt;
        $this->assertNotNull($expiresAt);
        $after = $expiresAt->modify('+1 day');

        $voucher->release(VoucherFactory::reservation(), $after);

        $this->assertSame(5, $voucher->balance->value);
        $this->assertSame(0, $voucher->balanceAt($after)->value);
    }

    /** The balance shown is the real one while the voucher is valid, and zero before the payment and after the expiry (RF-29). */
    public function test_the_balance_shown_follows_the_validity(): void
    {
        $voucher = VoucherFactory::pending();
        $this->assertSame(0, $voucher->balanceAt(VoucherFactory::now())->value);

        $voucher->activate(VoucherFactory::now());
        $expiresAt = $voucher->expiresAt;
        $this->assertNotNull($expiresAt);

        $this->assertSame(5, $voucher->balanceAt(VoucherFactory::now())->value);
        $this->assertSame(5, $voucher->balanceAt($expiresAt->modify('-1 second'))->value);
        $this->assertSame(0, $voucher->balanceAt($expiresAt)->value);
        $this->assertTrue($voucher->isValidAt(VoucherFactory::now()));
        $this->assertFalse($voucher->isValidAt($expiresAt));
    }

    /** Whatever happens to a voucher, the entries of its record add up to its balance, and the balance stays between zero and the total (BO-03, BO-09). */
    public function test_the_entries_always_add_up_to_the_balance(): void
    {
        $voucher = VoucherFactory::active(sessions: 3);
        $record = [];
        $now = VoucherFactory::now();
        $steps = [
            ['hold', 'a'], ['hold', 'b'], ['release', 'a'], ['consume', 'b'], ['hold', 'c'], ['hold', 'd'], ['hold', 'e'], ['release', 'c'], ['hold', 'f'],
        ];

        foreach ($steps as [$operation, $letter]) {
            $reservation = VoucherFactory::reservation('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0c0'.dechex(ord($letter) - 96));

            try {
                $voucher->{$operation}($reservation, $now);
            } catch (InsufficientBalanceException|BalanceAboveTotalException) {
                // A refused change leaves neither a balance nor an entry behind.
            }

            $record = [...$record, ...$voucher->pullMovements()];
            $this->assertSame($voucher->balance->value, VoucherMovement::balanceOf($record));
            $this->assertGreaterThanOrEqual(0, $voucher->balance->value);
            $this->assertLessThanOrEqual(3, $voucher->balance->value);
        }
    }

    /** The entries are handed over once, so saving twice cannot write them twice. */
    public function test_the_entries_are_pulled_once(): void
    {
        $voucher = VoucherFactory::active();

        $this->assertCount(1, $voucher->pullMovements());
        $this->assertSame([], $voucher->pullMovements());
    }

    /** A stored state with more sessions than the voucher was bought with is not a voucher. */
    public function test_a_stored_balance_above_the_total_is_refused(): void
    {
        $voucher = VoucherFactory::active();

        $this->expectException(InvalidValueException::class);

        PurchasedVoucher::reconstitute(
            $voucher->id, $voucher->barbershopId, $voucher->customerMembershipId, $voucher->voucherDefinitionId, $voucher->name, $voucher->price,
            $voucher->sessionsCount, $voucher->validityDays, $voucher->status, $voucher->activatedAt, $voucher->expiresAt, SessionBalance::of(6),
            $voucher->createdAt, $voucher->updatedAt,
        );
    }
}
