<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain\ValueObjects;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\Percentage;

final class MoneyTest extends TestCase
{
    /** Zero is a valid amount. */
    public function test_accepts_zero(): void
    {
        $this->assertSame(0, Money::of(0)->colones);
        $this->assertTrue(Money::zero()->equals(Money::of(0)));
    }

    /** A negative amount is rejected with a translatable error. */
    public function test_rejects_a_negative_amount(): void
    {
        try {
            Money::of(-1);
            $this->fail('A negative amount was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame('shared.money_negative', $exception->translationKey());
        }
    }

    /** Amounts add up. */
    public function test_adds_amounts(): void
    {
        $this->assertSame(3_500, Money::of(1_000)->plus(Money::of(2_500))->colones);
    }

    /** Subtraction down to exactly zero is allowed. */
    public function test_subtracts_down_to_zero(): void
    {
        $this->assertTrue(Money::of(500)->minus(Money::of(500))->equals(Money::zero()));
        $this->assertSame(300, Money::of(500)->minus(Money::of(200))->colones);
    }

    /** Subtraction cannot leave the result below zero. */
    public function test_rejects_a_subtraction_below_zero(): void
    {
        try {
            Money::of(500)->minus(Money::of(501));
            $this->fail('A result below zero was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame('shared.money_below_zero', $exception->translationKey());
        }
    }

    /** Amounts are compared by value. */
    public function test_compares_amounts(): void
    {
        $this->assertTrue(Money::of(2)->isGreaterThan(Money::of(1)));
        $this->assertFalse(Money::of(1)->isGreaterThan(Money::of(1)));
        $this->assertTrue(Money::of(1)->isLessThan(Money::of(2)));
        $this->assertFalse(Money::of(1)->isLessThan(Money::of(1)));
        $this->assertTrue(Money::of(7)->equals(Money::of(7)));
        $this->assertFalse(Money::of(7)->equals(Money::of(8)));
    }

    /** The percentage is rounded up to the next colon (PA-06). */
    #[DataProvider('percentages')]
    public function test_applies_a_percentage_rounding_up(int $amount, int $percentage, int $expected): void
    {
        $this->assertSame($expected, Money::of($amount)->percentage(Percentage::of($percentage))->colones);
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function percentages(): iterable
    {
        yield 'exact result' => [8_500, 30, 2_550];
        yield 'rounds up a fraction' => [1_001, 33, 331];
        yield 'rounds up a tiny fraction' => [1, 1, 1];
        yield 'zero percent' => [8_500, 0, 0];
        yield 'one hundred percent' => [8_500, 100, 8_500];
        yield 'zero amount' => [0, 50, 0];
    }
}
