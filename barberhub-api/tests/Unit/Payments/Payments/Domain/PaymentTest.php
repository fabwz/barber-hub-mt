<?php

declare(strict_types=1);

namespace Tests\Unit\Payments\Payments\Domain;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\Exceptions\PaymentTransitionNotAllowedException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;
use Src\Payments\Payments\Domain\ValueObjects\ProviderAmount;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Money;

final class PaymentTest extends TestCase
{
    private const string PAYMENT_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0e01';

    private const string BARBERSHOP_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0e02';

    private const string RESERVATION_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0e03';

    /** A new payment is pending, with the provider, method and amount it was created with, and not yet known by the provider (PA-02). */
    public function test_a_new_payment_is_pending_and_unknown_to_the_provider(): void
    {
        $payment = $this->payment();

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(PaymentProvider::Onvo, $payment->provider);
        $this->assertSame(PaymentMethod::SinpeMovil, $payment->method);
        $this->assertSame(2550, $payment->amount->colones);
        $this->assertSame(self::RESERVATION_ID, $payment->reservationId->value);
        $this->assertNull($payment->providerPaymentId);
        $this->assertNull($payment->providerAmount);
        $this->assertNull($payment->failureReason);
        $this->assertEquals($this->at(), $payment->createdAt);
        $this->assertEquals($this->at(), $payment->updatedAt);
    }

    /** A payment of zero colones is not a payment. */
    public function test_the_amount_must_be_positive(): void
    {
        $this->expectException(InvalidValueException::class);

        $this->payment(Money::zero());
    }

    /** The charge keeps what the provider answered, once, while the payment is pending. */
    public function test_the_charge_is_registered_once(): void
    {
        $payment = $this->payment();

        $payment->registerCharge('onvo_123', ProviderAmount::of('USD', 510), $this->at('+1 minute'));

        $this->assertSame('onvo_123', $payment->providerPaymentId);
        $this->assertSame('USD', $payment->providerAmount?->currency);
        $this->assertSame(510, $payment->providerAmount->minorUnits);
        $this->assertEquals($this->at('+1 minute'), $payment->updatedAt);

        $this->expectException(LogicException::class);
        $payment->registerCharge('onvo_456', ProviderAmount::of('USD', 510), $this->at('+2 minutes'));
    }

    /** The identifier of the provider cannot be blank. */
    public function test_the_provider_identifier_cannot_be_blank(): void
    {
        $this->expectException(InvalidValueException::class);

        $this->payment()->registerCharge('  ', ProviderAmount::of('USD', 510), $this->at());
    }

    /** The provider amount needs an ISO code and a positive number of units. */
    public function test_the_provider_amount_is_validated(): void
    {
        foreach ([['usd', 100], ['US', 100], ['USD', 0], ['USD', -5]] as [$currency, $units]) {
            try {
                ProviderAmount::of($currency, $units);
                $this->fail("Accepted {$currency} {$units}.");
            } catch (InvalidValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** A pending payment ends approved, rejected or failed, and an approved one can be refunded. */
    #[DataProvider('allowedTransitions')]
    public function test_the_allowed_transitions(PaymentStatus $from, string $action, PaymentStatus $to): void
    {
        $payment = $this->inStatus($from);

        $this->apply($payment, $action);

        $this->assertSame($to, $payment->status);
        $this->assertEquals($this->at('+1 hour'), $payment->updatedAt);
    }

    /**
     * Every other jump answers with the exception that becomes a 409; the rejected, failed and refunded payments are final.
     */
    #[DataProvider('forbiddenTransitions')]
    public function test_the_forbidden_transitions(PaymentStatus $from, string $action): void
    {
        $payment = $this->inStatus($from);

        try {
            $this->apply($payment, $action);
            $this->fail("A {$from->value} payment accepted {$action}.");
        } catch (PaymentTransitionNotAllowedException $exception) {
            $this->assertSame('payments.transition_not_allowed', $exception->translationKey());
            $this->assertSame($from, $payment->status);
        }
    }

    /** The status table agrees: only pending and approved payments count for the reservation, and three statuses are final. */
    public function test_the_status_knows_what_is_active_and_final(): void
    {
        $this->assertSame([PaymentStatus::Pending, PaymentStatus::Approved], array_values(array_filter(PaymentStatus::cases(), fn (PaymentStatus $status): bool => $status->isActive())));
        $this->assertSame([PaymentStatus::Rejected, PaymentStatus::Failed, PaymentStatus::Refunded], array_values(array_filter(PaymentStatus::cases(), fn (PaymentStatus $status): bool => $status->isFinal())));
    }

    /** A rejection or a failure keeps its reason, trimmed and cut to what the table stores; an approval keeps none. */
    public function test_the_reason_of_a_failure_is_kept(): void
    {
        $rejected = $this->payment();
        $rejected->reject('  Card declined  ', $this->at());
        $failed = $this->payment();
        $failed->fail(str_repeat('é', 400), $this->at());
        $blank = $this->payment();
        $blank->reject('   ', $this->at());
        $approved = $this->inStatus(PaymentStatus::Approved);

        $this->assertSame('Card declined', $rejected->failureReason);
        $this->assertSame(Payment::MAX_FAILURE_REASON_LENGTH, mb_strlen((string) $failed->failureReason));
        $this->assertNull($blank->failureReason);
        $this->assertNull($approved->failureReason);
    }

    /** A payment the provider never saw cannot be approved. */
    public function test_a_payment_without_a_charge_cannot_be_approved(): void
    {
        $this->expectException(LogicException::class);

        $this->payment()->approve($this->at());
    }

    /** Rebuilding a payment from storage gives back the same state and nothing else. */
    public function test_a_payment_is_rebuilt_from_its_stored_state(): void
    {
        $payment = Payment::reconstitute(
            new PaymentId(self::PAYMENT_ID),
            new BarbershopId(self::BARBERSHOP_ID),
            new ReservationId(self::RESERVATION_ID),
            PaymentProvider::Paypal,
            PaymentMethod::Card,
            Money::of(8500),
            'paypal_9',
            ProviderAmount::of('USD', 1700),
            PaymentStatus::Refunded,
            null,
            $this->at(),
            $this->at('+1 day'),
        );

        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame('paypal_9', $payment->providerPaymentId);
        $this->assertSame(8500, $payment->amount->colones);
    }

    /**
     * @return array<string, array{PaymentStatus, string, PaymentStatus}>
     */
    public static function allowedTransitions(): array
    {
        return [
            'pending to approved' => [PaymentStatus::Pending, 'approve', PaymentStatus::Approved],
            'pending to rejected' => [PaymentStatus::Pending, 'reject', PaymentStatus::Rejected],
            'pending to failed' => [PaymentStatus::Pending, 'fail', PaymentStatus::Failed],
            'approved to refunded' => [PaymentStatus::Approved, 'refund', PaymentStatus::Refunded],
        ];
    }

    /**
     * @return array<string, array{PaymentStatus, string}>
     */
    public static function forbiddenTransitions(): array
    {
        $cases = [];

        foreach ([
            'pending' => ['refund'],
            'approved' => ['approve', 'reject', 'fail'],
            'rejected' => ['approve', 'reject', 'fail', 'refund'],
            'failed' => ['approve', 'reject', 'fail', 'refund'],
            'refunded' => ['approve', 'reject', 'fail', 'refund'],
        ] as $status => $actions) {
            foreach ($actions as $action) {
                $cases["{$status} cannot {$action}"] = [PaymentStatus::from($status), $action];
            }
        }

        return $cases;
    }

    private function apply(Payment $payment, string $action): void
    {
        $at = $this->at('+1 hour');

        if ($action === 'approve' && $payment->status === PaymentStatus::Pending) {
            $payment->registerCharge('onvo_123', ProviderAmount::of('USD', 510), $this->at());
        }

        match ($action) {
            'approve' => $payment->approve($at),
            'reject' => $payment->reject('Card declined', $at),
            'fail' => $payment->fail('Expired', $at),
            'refund' => $payment->refund($at),
            default => throw new LogicException("Unknown action {$action}."),
        };
    }

    private function at(string $modifier = 'now'): DateTimeImmutable
    {
        return (new DateTimeImmutable('2030-03-01 08:00:00-06:00'))->modify($modifier);
    }

    private function payment(?Money $amount = null): Payment
    {
        return Payment::create(
            new PaymentId(self::PAYMENT_ID),
            new BarbershopId(self::BARBERSHOP_ID),
            new ReservationId(self::RESERVATION_ID),
            PaymentProvider::Onvo,
            PaymentMethod::SinpeMovil,
            $amount ?? Money::of(2550),
            $this->at(),
        );
    }

    /** A payment in the status, reached through the transitions the domain allows. */
    private function inStatus(PaymentStatus $status): Payment
    {
        $payment = $this->payment();

        if ($status === PaymentStatus::Pending) {
            return $payment;
        }

        if ($status === PaymentStatus::Rejected || $status === PaymentStatus::Failed) {
            $status === PaymentStatus::Rejected ? $payment->reject('x', $this->at()) : $payment->fail('x', $this->at());

            return $payment;
        }

        $payment->registerCharge('onvo_123', ProviderAmount::of('USD', 510), $this->at());
        $payment->approve($this->at());

        if ($status === PaymentStatus::Refunded) {
            $payment->refund($this->at());
        }

        return $payment;
    }
}
