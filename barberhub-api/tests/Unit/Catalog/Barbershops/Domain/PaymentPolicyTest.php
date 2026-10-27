<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Barbershops\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentGateway;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentMode;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentPolicy;
use Src\Shared\Domain\Exceptions\InvalidValueException;

/** The payment policy of a barbershop and the rules it keeps (RF-08, PA-01, PA-02, CA-01). */
final class PaymentPolicyTest extends TestCase
{
    /** A new barbershop charges nothing, has no gateway and lets the customer cancel free up to 24 hours before. */
    public function test_the_initial_policy_charges_nothing(): void
    {
        $policy = PaymentPolicy::initial();

        $this->assertSame(PaymentMode::None, $policy->mode);
        $this->assertNull($policy->depositPercentage);
        $this->assertSame(24, $policy->cancellationDeadlineHours);
        $this->assertNull($policy->gateway);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function acceptedPercentages(): iterable
    {
        yield '10 %' => [10];
        yield '30 %' => [30];
        yield '100 %' => [100];
    }

    /** A deposit from 10 to 100 % is accepted. */
    #[DataProvider('acceptedPercentages')]
    public function test_a_deposit_inside_the_range_is_accepted(int $percentage): void
    {
        $policy = PaymentPolicy::of(PaymentMode::Deposit, $percentage, 4, PaymentGateway::Onvo);

        $this->assertSame($percentage, $policy->depositPercentage);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function rejectedPercentages(): iterable
    {
        yield '5 %' => [5];
        yield '9 %' => [9];
        yield '101 %' => [101];
        yield '120 %' => [120];
        yield '0 %' => [0];
    }

    /** A deposit under 10 % or over 100 % is rejected. */
    #[DataProvider('rejectedPercentages')]
    public function test_a_deposit_outside_the_range_is_rejected(int $percentage): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('barbershops.deposit_percentage_invalid');

        PaymentPolicy::of(PaymentMode::Deposit, $percentage, 4, PaymentGateway::Onvo);
    }

    /** The deposit mode needs its percentage. */
    public function test_a_deposit_requires_a_percentage(): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('barbershops.deposit_percentage_required');

        PaymentPolicy::of(PaymentMode::Deposit, null, 4, PaymentGateway::Onvo);
    }

    /**
     * @return iterable<string, array{PaymentMode}>
     */
    public static function modesWithoutDeposit(): iterable
    {
        yield 'no payment' => [PaymentMode::None];
        yield 'full payment' => [PaymentMode::Full];
    }

    /** The percentage must be null in the other modes. */
    #[DataProvider('modesWithoutDeposit')]
    public function test_the_percentage_must_be_null_outside_a_deposit(PaymentMode $mode): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('barbershops.deposit_percentage_not_allowed');

        PaymentPolicy::of($mode, 30, 4, PaymentGateway::Onvo);
    }

    /**
     * @return iterable<string, array{PaymentMode, int|null}>
     */
    public static function chargingModes(): iterable
    {
        yield 'deposit' => [PaymentMode::Deposit, 30];
        yield 'full payment' => [PaymentMode::Full, null];
    }

    /** A mode that charges needs a gateway (PA-02). */
    #[DataProvider('chargingModes')]
    public function test_a_charge_requires_a_gateway(PaymentMode $mode, ?int $percentage): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('barbershops.payment_gateway_required');

        PaymentPolicy::of($mode, $percentage, 4, null);
    }

    /** Without payment the gateway is optional. */
    public function test_no_payment_works_with_or_without_a_gateway(): void
    {
        $this->assertNull(PaymentPolicy::of(PaymentMode::None, null, 4, null)->gateway);
        $this->assertSame(PaymentGateway::Paypal, PaymentPolicy::of(PaymentMode::None, null, 4, PaymentGateway::Paypal)->gateway);
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function deadlines(): iterable
    {
        yield '-1 h' => [-1, false];
        yield '0 h' => [0, true];
        yield '12 h' => [12, true];
        yield '720 h' => [720, true];
        yield '721 h' => [721, false];
    }

    /** The deadline goes from 0 to 720 hours. */
    #[DataProvider('deadlines')]
    public function test_the_deadline_stays_between_0_and_720_hours(int $hours, bool $accepted): void
    {
        if (! $accepted) {
            $this->expectException(InvalidValueException::class);
            $this->expectExceptionMessage('barbershops.cancellation_deadline_invalid');
        }

        $this->assertSame($hours, PaymentPolicy::of(PaymentMode::None, null, $hours, null)->cancellationDeadlineHours);
    }
}
