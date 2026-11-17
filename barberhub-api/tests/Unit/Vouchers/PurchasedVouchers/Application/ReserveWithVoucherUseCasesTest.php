<?php

declare(strict_types=1);

namespace Tests\Unit\Vouchers\PurchasedVouchers\Application;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\CoveringDefinitions;
use Src\Vouchers\PurchasedVouchers\Application\UseCases\ConsumeVoucherSession;
use Src\Vouchers\PurchasedVouchers\Application\UseCases\HoldVoucherSession;
use Src\Vouchers\PurchasedVouchers\Application\UseCases\ReleaseVoucherSession;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\PurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\InsufficientBalanceException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherExpiredException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotApplicableException;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\PurchasedVoucherId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\VoucherMovementType;
use Tests\Support\Vouchers\InMemoryPurchasedVoucherRepository;
use Tests\Support\Vouchers\VoucherFactory;

/** Setting a session aside for a reservation, giving it back and leaving it used (RF-28, BO-03 to BO-08, CA-02, CA-03, CA-06). */
final class ReserveWithVoucherUseCasesTest extends TestCase
{
    private const string OTHER_DEFINITION = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0b01';

    private const string SECOND_VOUCHER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0b02';

    private const string OTHER_CUSTOMER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0b03';

    private const string OTHER_VOUCHER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0b04';

    private const string BEAD = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0b05';

    private InMemoryPurchasedVoucherRepository $vouchers;

    /** Both definitions cover the classic cut; only the first one covers the fade. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->vouchers = new InMemoryPurchasedVoucherRepository;
    }

    /** With one session left, the first reservation takes it and the second finds the balance insufficient: it ends at 0, never at -1 (BO-03). */
    public function test_the_last_session_goes_to_one_reservation_and_the_balance_ends_at_zero(): void
    {
        $this->store(VoucherFactory::active(1));
        $first = '0192f0c4-0000-7000-8000-0000000000a1';
        $second = '0192f0c4-0000-7000-8000-0000000000a2';

        $taken = $this->hold()->execute(VoucherFactory::MEMBERSHIP, VoucherFactory::CLASSIC_CUT, $first, $this->at('2030-03-05 10:00'), VoucherFactory::now());

        $this->assertSame(VoucherFactory::VOUCHER, $taken);

        try {
            $this->hold()->execute(VoucherFactory::MEMBERSHIP, VoucherFactory::CLASSIC_CUT, $second, $this->at('2030-03-05 11:00'), VoucherFactory::now());
            $this->fail('The second reservation took a session that did not exist.');
        } catch (InsufficientBalanceException) {
            $this->assertSame(0, $this->balance(VoucherFactory::VOUCHER));
            $this->assertSame([VoucherMovementType::Purchase, VoucherMovementType::Hold], $this->types(VoucherFactory::VOUCHER));
        }
    }

    /** A customer with no paid voucher has no balance; a voucher whose payment is pending does not count (BO-02, BO-03). */
    public function test_a_customer_without_a_paid_voucher_has_an_insufficient_balance(): void
    {
        $this->store(VoucherFactory::pending());

        $this->expectException(InsufficientBalanceException::class);

        $this->hold()->execute(VoucherFactory::MEMBERSHIP, VoucherFactory::CLASSIC_CUT, VoucherFactory::RESERVATION, $this->at('2030-03-05 10:00'), VoucherFactory::now());
    }

    /** An appointment outside the validity is refused as expired: the one at the expiry instant is, one a minute earlier is not (BO-06, BO-07). */
    public function test_an_appointment_outside_the_validity_is_expired(): void
    {
        $this->store(VoucherFactory::active(5, 30));

        $this->assertSame(VoucherFactory::VOUCHER, $this->hold()->execute(VoucherFactory::MEMBERSHIP, VoucherFactory::CLASSIC_CUT, VoucherFactory::RESERVATION, $this->at('2030-03-31 07:59'), VoucherFactory::now()));

        $this->expectException(VoucherExpiredException::class);

        try {
            $this->hold()->execute(VoucherFactory::MEMBERSHIP, VoucherFactory::CLASSIC_CUT, '0192f0c4-0000-7000-8000-0000000000a2', $this->at('2030-03-31 08:00'), VoucherFactory::now());
        } finally {
            $this->assertSame(4, $this->balance(VoucherFactory::VOUCHER));
        }
    }

    /** The validity is judged at the appointment, not at the moment of booking: a voucher valid today is expired for a later date (BO-06). */
    public function test_a_voucher_valid_today_does_not_cover_an_appointment_after_its_expiry(): void
    {
        $this->store(VoucherFactory::active(5, 10));

        $this->expectException(VoucherExpiredException::class);

        $this->hold()->execute(VoucherFactory::MEMBERSHIP, VoucherFactory::CLASSIC_CUT, VoucherFactory::RESERVATION, $this->at('2030-04-20 10:00'), VoucherFactory::now());
    }

    /** With two applicable vouchers the one that expires first is used, whatever the order they were bought in (BO-08). */
    public function test_the_voucher_that_expires_first_is_used_first(): void
    {
        $this->store(VoucherFactory::active(5, 90, null, VoucherFactory::VOUCHER));
        $this->store(VoucherFactory::active(5, 30, null, self::SECOND_VOUCHER));

        $taken = $this->hold()->execute(VoucherFactory::MEMBERSHIP, VoucherFactory::CLASSIC_CUT, VoucherFactory::RESERVATION, $this->at('2030-03-05 10:00'), VoucherFactory::now());

        $this->assertSame(self::SECOND_VOUCHER, $taken);
        $this->assertSame(4, $this->balance(self::SECOND_VOUCHER));
        $this->assertSame(5, $this->balance(VoucherFactory::VOUCHER));
    }

    /** When the one that expires first has no session left, the next one is used instead of failing (BO-03, BO-08). */
    public function test_a_voucher_without_sessions_is_skipped_for_the_next_one(): void
    {
        $this->store(VoucherFactory::active(1, 30, null, self::SECOND_VOUCHER));
        $this->store(VoucherFactory::active(5, 90, null, VoucherFactory::VOUCHER));
        $this->hold()->execute(VoucherFactory::MEMBERSHIP, VoucherFactory::CLASSIC_CUT, '0192f0c4-0000-7000-8000-0000000000a1', $this->at('2030-03-05 10:00'), VoucherFactory::now());

        $taken = $this->hold()->execute(VoucherFactory::MEMBERSHIP, VoucherFactory::CLASSIC_CUT, '0192f0c4-0000-7000-8000-0000000000a2', $this->at('2030-03-05 12:00'), VoucherFactory::now());

        $this->assertSame(VoucherFactory::VOUCHER, $taken);
        $this->assertSame(0, $this->balance(self::SECOND_VOUCHER));
    }

    /** An expired voucher is not chosen when another one is valid at the appointment (BO-06, BO-08). */
    public function test_an_expired_voucher_is_left_for_the_valid_one(): void
    {
        $this->store(VoucherFactory::active(5, 10, null, self::SECOND_VOUCHER));
        $this->store(VoucherFactory::active(5, 90, null, VoucherFactory::VOUCHER));

        $taken = $this->hold()->execute(VoucherFactory::MEMBERSHIP, VoucherFactory::CLASSIC_CUT, VoucherFactory::RESERVATION, $this->at('2030-04-20 10:00'), VoucherFactory::now());

        $this->assertSame(VoucherFactory::VOUCHER, $taken);
    }

    /** A service the voucher does not cover cannot use it and no session is taken (BO-06). */
    public function test_a_service_that_is_not_covered_does_not_use_the_voucher(): void
    {
        $this->store(VoucherFactory::active(5, 90, null, VoucherFactory::VOUCHER, VoucherFactory::BARBERSHOP, VoucherFactory::MEMBERSHIP, self::OTHER_DEFINITION));

        try {
            $this->hold()->execute(VoucherFactory::MEMBERSHIP, self::BEAD, VoucherFactory::RESERVATION, $this->at('2030-03-05 10:00'), VoucherFactory::now());
            $this->fail('A voucher paid a service it does not cover.');
        } catch (VoucherNotApplicableException $exception) {
            $this->assertSame('conflicto', $exception->rule());
            $this->assertSame(5, $this->balance(VoucherFactory::VOUCHER));
        }
    }

    /** A voucher that covers the service is chosen over one that does not, even if the other expires first (BO-06, BO-08). */
    public function test_only_the_vouchers_that_cover_the_service_are_considered(): void
    {
        $this->store(VoucherFactory::active(5, 10, null, self::SECOND_VOUCHER, VoucherFactory::BARBERSHOP, VoucherFactory::MEMBERSHIP, self::OTHER_DEFINITION));
        $this->store(VoucherFactory::active(5, 90, null, VoucherFactory::VOUCHER));

        $taken = $this->hold()->execute(VoucherFactory::MEMBERSHIP, VoucherFactory::FADE, VoucherFactory::RESERVATION, $this->at('2030-03-05 10:00'), VoucherFactory::now());

        $this->assertSame(VoucherFactory::VOUCHER, $taken);
    }

    /** The vouchers of another customer are never used (CL-04). */
    public function test_the_voucher_of_another_customer_is_not_used(): void
    {
        $this->store(VoucherFactory::active(5, 90, null, self::OTHER_VOUCHER, VoucherFactory::BARBERSHOP, self::OTHER_CUSTOMER));

        $this->expectException(InsufficientBalanceException::class);

        $this->hold()->execute(VoucherFactory::MEMBERSHIP, VoucherFactory::CLASSIC_CUT, VoucherFactory::RESERVATION, $this->at('2030-03-05 10:00'), VoucherFactory::now());
    }

    /** Giving the session back restores the balance and leaves its entry; the sum of the record is the balance (CA-02, BO-05, BO-09). */
    public function test_releasing_gives_the_session_back_once(): void
    {
        $this->store(VoucherFactory::active(2));
        $this->hold()->execute(VoucherFactory::MEMBERSHIP, VoucherFactory::CLASSIC_CUT, VoucherFactory::RESERVATION, $this->at('2030-03-05 10:00'), VoucherFactory::now());

        $this->release()->execute(VoucherFactory::VOUCHER, VoucherFactory::RESERVATION, VoucherFactory::now());
        $this->release()->execute(VoucherFactory::VOUCHER, VoucherFactory::RESERVATION, VoucherFactory::now());
        $this->consume()->execute(VoucherFactory::VOUCHER, VoucherFactory::RESERVATION, VoucherFactory::now());

        $this->assertSame(2, $this->balance(VoucherFactory::VOUCHER));
        $this->assertSame([VoucherMovementType::Purchase, VoucherMovementType::Hold, VoucherMovementType::Release], $this->types(VoucherFactory::VOUCHER));
        $this->assertSame(2, $this->sum(VoucherFactory::VOUCHER));
    }

    /** Using the session keeps the balance down, leaves its entry once and a later release does not give it back (CA-03, BO-05, CA-06). */
    public function test_consuming_leaves_the_session_used_once(): void
    {
        $this->store(VoucherFactory::active(2));
        $this->hold()->execute(VoucherFactory::MEMBERSHIP, VoucherFactory::CLASSIC_CUT, VoucherFactory::RESERVATION, $this->at('2030-03-05 10:00'), VoucherFactory::now());

        $this->consume()->execute(VoucherFactory::VOUCHER, VoucherFactory::RESERVATION, VoucherFactory::now());
        $this->consume()->execute(VoucherFactory::VOUCHER, VoucherFactory::RESERVATION, VoucherFactory::now());
        $this->release()->execute(VoucherFactory::VOUCHER, VoucherFactory::RESERVATION, VoucherFactory::now());

        $this->assertSame(1, $this->balance(VoucherFactory::VOUCHER));
        $this->assertSame([VoucherMovementType::Purchase, VoucherMovementType::Hold, VoucherMovementType::Consume], $this->types(VoucherFactory::VOUCHER));
        $this->assertSame(1, $this->sum(VoucherFactory::VOUCHER));
    }

    private function hold(): HoldVoucherSession
    {
        $covering = new class implements CoveringDefinitions
        {
            public function idsCovering(string $serviceId): array
            {
                return match ($serviceId) {
                    VoucherFactory::CLASSIC_CUT => [VoucherFactory::DEFINITION, ReserveWithVoucherUseCasesTest::definition()],
                    VoucherFactory::FADE => [VoucherFactory::DEFINITION],
                    default => [],
                };
            }
        };

        return new HoldVoucherSession($this->vouchers, $covering);
    }

    private function release(): ReleaseVoucherSession
    {
        return new ReleaseVoucherSession($this->vouchers);
    }

    private function consume(): ConsumeVoucherSession
    {
        return new ConsumeVoucherSession($this->vouchers);
    }

    /** @internal Used by the port of the test. */
    public static function definition(): string
    {
        return self::OTHER_DEFINITION;
    }

    private function store(PurchasedVoucher $voucher): void
    {
        $this->vouchers->save($voucher);
    }

    private function balance(string $voucher): int
    {
        return $this->vouchers->find(new PurchasedVoucherId($voucher))?->balance->value ?? -1;
    }

    /**
     * The kinds of entry of the record of the voucher.
     *
     * @return list<VoucherMovementType>
     */
    private function types(string $voucher): array
    {
        return array_map(fn ($movement): VoucherMovementType => $movement->type, $this->vouchers->movementsOf(new PurchasedVoucherId($voucher)));
    }

    private function sum(string $voucher): int
    {
        return array_sum(array_map(fn ($movement): int => $movement->sessionsDelta, $this->vouchers->movementsOf(new PurchasedVoucherId($voucher))));
    }

    private function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new \DateTimeZone('America/Costa_Rica'));
    }
}
