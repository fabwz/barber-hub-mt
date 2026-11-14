<?php

declare(strict_types=1);

namespace Tests\Unit\Payments\Payments\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;
use Src\Payments\Payments\Domain\ValueObjects\ProviderAmount;
use Src\Payments\Payments\Domain\ValueObjects\PurchasedVoucherId;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Money;

/** The payment of a purchased voucher (RF-27): it pays for a voucher or for a reservation, never both nor none. */
final class PaymentOfVoucherTest extends TestCase
{
    private const string PAYMENT = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0e01';

    private const string BARBERSHOP = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0e02';

    private const string VOUCHER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0e04';

    private const string RESERVATION = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0e03';

    /** It starts pending for the voucher, in full, and with no reservation. */
    public function test_a_payment_of_a_voucher_starts_pending_with_the_voucher_and_no_reservation(): void
    {
        $payment = $this->forVoucher();

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(self::VOUCHER, $payment->purchasedVoucherId?->value);
        $this->assertNull($payment->reservationId);
        $this->assertTrue($payment->isOfVoucher());
        $this->assertSame(30000, $payment->amount->colones);
    }

    /** The payment of a reservation is not of a voucher. */
    public function test_a_payment_of_a_reservation_is_not_of_a_voucher(): void
    {
        $payment = Payment::create(new PaymentId(self::PAYMENT), new BarbershopId(self::BARBERSHOP), new ReservationId(self::RESERVATION), PaymentProvider::Onvo, PaymentMethod::Card, Money::of(2100), $this->at());

        $this->assertFalse($payment->isOfVoucher());
        $this->assertNull($payment->purchasedVoucherId);
    }

    /** A payment of zero colones is not a payment, of a voucher either. */
    public function test_the_amount_of_a_voucher_must_be_positive(): void
    {
        $this->expectException(InvalidValueException::class);

        Payment::createForVoucher(new PaymentId(self::PAYMENT), new BarbershopId(self::BARBERSHOP), new PurchasedVoucherId(self::VOUCHER), PaymentProvider::Onvo, PaymentMethod::Card, Money::zero(), $this->at());
    }

    /** It follows the same transitions: the charge is registered once and the payment can be approved. */
    public function test_it_is_approved_after_its_charge_exists(): void
    {
        $payment = $this->forVoucher();
        $payment->registerCharge('pi_1', ProviderAmount::of('CRC', 3000000), $this->at());

        $payment->approve($this->at());

        $this->assertSame(PaymentStatus::Approved, $payment->status);
    }

    /** What is stored must name one subject: both or none are rejected when it is rebuilt, as the CHECK of the table does. */
    public function test_a_stored_payment_with_both_subjects_or_none_is_rejected(): void
    {
        foreach ([[new ReservationId(self::RESERVATION), new PurchasedVoucherId(self::VOUCHER)], [null, null]] as [$reservation, $voucher]) {
            try {
                Payment::reconstitute(new PaymentId(self::PAYMENT), new BarbershopId(self::BARBERSHOP), $reservation, PaymentProvider::Onvo, PaymentMethod::Card, Money::of(30000), null, null, PaymentStatus::Pending, null, $this->at(), $this->at(), false, null, $voucher);
                $this->fail('A payment with '.($reservation === null ? 'no subject' : 'two subjects').' was rebuilt.');
            } catch (InvalidValueException $exception) {
                $this->assertSame('payments.subject_invalid', $exception->translationKey());
            }
        }
    }

    /** A stored payment of a voucher is rebuilt with its voucher. */
    public function test_a_stored_payment_of_a_voucher_is_rebuilt(): void
    {
        $payment = Payment::reconstitute(new PaymentId(self::PAYMENT), new BarbershopId(self::BARBERSHOP), null, PaymentProvider::Paypal, PaymentMethod::Card, Money::of(30000), 'order_1', null, PaymentStatus::Pending, null, $this->at(), $this->at(), false, null, new PurchasedVoucherId(self::VOUCHER));

        $this->assertTrue($payment->isOfVoucher());
        $this->assertSame('order_1', $payment->providerPaymentId);
    }

    private function forVoucher(): Payment
    {
        return Payment::createForVoucher(new PaymentId(self::PAYMENT), new BarbershopId(self::BARBERSHOP), new PurchasedVoucherId(self::VOUCHER), PaymentProvider::Onvo, PaymentMethod::Card, Money::of(30000), $this->at());
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-03-01 08:00:00-06:00');
    }
}
