<?php

declare(strict_types=1);

namespace Tests\Unit\Payments\Payments\Application;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Src\Payments\Payments\Application\SettlePayment;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\Exceptions\ActivePaymentExistsException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;
use Src\Payments\Payments\Domain\ValueObjects\ProviderAmount;
use Src\Payments\Payments\Domain\ValueObjects\PurchasedVoucherId;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Money;
use Tests\Support\Payments\InMemoryPaymentRepository;
use Tests\Support\Payments\RecordingSettlementPorts;

/** What a payment that the provider approved or refused does to what it paid for (PA-06, BO-02, PA-07). */
final class SettlePaymentOfVoucherTest extends TestCase
{
    private const string VOUCHER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d04';

    private const string RESERVATION = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d03';

    private const string BARBERSHOP = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d02';

    private InMemoryPaymentRepository $payments;

    private RecordingSettlementPorts $ports;

    private SettlePayment $settle;

    /** A settlement whose neighbours record what they were asked. */
    protected function setUp(): void
    {
        $this->payments = new InMemoryPaymentRepository;
        $this->ports = new RecordingSettlementPorts;
        $this->settle = new SettlePayment($this->payments, $this->ports, $this->ports);
    }

    /** An approved payment of a voucher activates the voucher and not a reservation, and is saved approved (BO-02). */
    public function test_an_approved_payment_of_a_voucher_activates_the_voucher(): void
    {
        $payment = $this->voucherPayment();

        $this->settle->apply($payment, PaymentStatus::Approved, null, $this->at());

        $this->assertSame([self::VOUCHER], $this->ports->activated);
        $this->assertSame([], $this->ports->confirmed);
        $this->assertSame(PaymentStatus::Approved, $this->payments->find($payment->id)?->status);
        $this->assertFalse($payment->refundPending);
    }

    /** An approved payment of a reservation confirms the reservation and does not touch the vouchers. */
    public function test_an_approved_payment_of_a_reservation_confirms_it_and_activates_nothing(): void
    {
        $payment = Payment::create(new PaymentId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d01'), new BarbershopId(self::BARBERSHOP), new ReservationId(self::RESERVATION), PaymentProvider::Onvo, PaymentMethod::Card, Money::of(2100), $this->at());
        $payment->registerCharge('pi_1', ProviderAmount::of('CRC', 210000), $this->at());

        $this->settle->apply($payment, PaymentStatus::Approved, null, $this->at());

        $this->assertSame([self::RESERVATION], $this->ports->confirmed);
        $this->assertSame([], $this->ports->activated);
    }

    /** A voucher that cannot be activated leaves the payment approved and marked to give back (PA-07). */
    public function test_a_voucher_that_cannot_be_activated_leaves_the_payment_to_give_back(): void
    {
        $this->ports->activationWorks = false;
        $payment = $this->voucherPayment();

        $this->settle->apply($payment, PaymentStatus::Approved, null, $this->at());

        $this->assertSame(PaymentStatus::Approved, $payment->status);
        $this->assertTrue($payment->refundPending);
    }

    /** A refused or failed payment, and one still pending, activate nothing: the voucher stays unpaid (PA-03, BO-02). */
    public function test_a_payment_that_is_not_approved_activates_nothing(): void
    {
        foreach ([[PaymentStatus::Rejected, 'declined'], [PaymentStatus::Failed, 'expired'], [PaymentStatus::Pending, null]] as [$status, $reason]) {
            $payment = $this->voucherPayment();
            $this->settle->apply($payment, $status, $reason, $this->at());

            $this->assertSame($status, $payment->status);
        }

        $this->assertSame([], $this->ports->activated);
        $this->assertSame([], $this->ports->confirmed);
    }

    /** A voucher has one payment pending or approved at a time, as the index of the table demands (idempotency of the buy button). */
    public function test_a_voucher_cannot_have_two_active_payments(): void
    {
        $this->voucherPayment();

        $this->expectException(ActivePaymentExistsException::class);

        $this->voucherPayment();
    }

    private function voucherPayment(): Payment
    {
        $payment = Payment::createForVoucher($this->payments->nextId(), new BarbershopId(self::BARBERSHOP), new PurchasedVoucherId(self::VOUCHER), PaymentProvider::Onvo, PaymentMethod::Card, Money::of(30000), $this->at());
        $this->payments->save($payment);
        $payment->registerCharge('pi_'.$this->payments->count(), ProviderAmount::of('CRC', 3000000), $this->at());

        return $payment;
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-03-01 08:00:00-06:00');
    }
}
