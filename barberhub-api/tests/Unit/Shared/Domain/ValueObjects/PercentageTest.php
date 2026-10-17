<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain\ValueObjects;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\Percentage;

final class PercentageTest extends TestCase
{
    /** Both ends of the range are valid. */
    public function test_accepts_the_ends_of_the_range(): void
    {
        $this->assertSame(0, Percentage::of(0)->value);
        $this->assertSame(100, Percentage::of(100)->value);
        $this->assertSame(37, Percentage::of(37)->value);
    }

    /** Values outside 0 to 100 are rejected with a translatable error. */
    #[DataProvider('outOfRange')]
    public function test_rejects_values_out_of_range(int $value): void
    {
        try {
            Percentage::of($value);
            $this->fail('An out-of-range percentage was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame('shared.percentage_out_of_range', $exception->translationKey());
        }
    }

    /** @return iterable<string, array{int}> */
    public static function outOfRange(): iterable
    {
        yield 'below zero' => [-1];
        yield 'above one hundred' => [101];
    }

    /** Percentages are compared by value. */
    public function test_compares_by_value(): void
    {
        $this->assertTrue(Percentage::of(30)->equals(Percentage::of(30)));
        $this->assertFalse(Percentage::of(30)->equals(Percentage::of(31)));
    }
}
