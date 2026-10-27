<?php

declare(strict_types=1);

namespace Tests\Unit\Payments\Payments\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Payments\Payments\Domain\Services\PaymentAmountCalculator;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\Percentage;

/** The amount of a payment is decided in the server from the price of the reservation and the policy (PA-06). */
final class PaymentAmountCalculatorTest extends TestCase
{
    /** A full payment is the price. */
    public function test_the_total_is_the_price(): void
    {
        $this->assertSame(8500, (new PaymentAmountCalculator)->total(Money::of(8500))->colones);
    }

    /** The deposit is the percentage of the price, rounded up to the colon. */
    #[DataProvider('deposits')]
    public function test_the_deposit_rounds_up_to_the_colon(int $price, int $percentage, int $expected): void
    {
        $this->assertSame($expected, (new PaymentAmountCalculator)->deposit(Money::of($price), Percentage::of($percentage))->colones);
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function deposits(): array
    {
        return [
            '7.000 at 30 % is exact' => [7000, 30, 2100],
            '8.500 at 30 % is exact' => [8500, 30, 2550],
            '5.001 at 33 % rounds up' => [5001, 33, 1651],
            '1 at 10 % rounds up to 1' => [1, 10, 1],
            '9.999 at 10 % rounds up' => [9999, 10, 1000],
            '8.500 at 100 % is the price' => [8500, 100, 8500],
            '6.667 at 15 % rounds up' => [6667, 15, 1001],
        ];
    }
}
