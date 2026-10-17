<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain\ValueObjects;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\Duration;

final class DurationTest extends TestCase
{
    /** One minute is the shortest valid duration. */
    public function test_accepts_a_positive_number_of_minutes(): void
    {
        $this->assertSame(1, Duration::ofMinutes(1)->minutes);
        $this->assertSame(45, Duration::ofMinutes(45)->minutes);
    }

    /** Zero and negative minutes are rejected with a translatable error. */
    #[DataProvider('invalidMinutes')]
    public function test_rejects_zero_and_negative_minutes(int $minutes): void
    {
        try {
            Duration::ofMinutes($minutes);
            $this->fail('A non-positive duration was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame('shared.duration_not_positive', $exception->translationKey());
        }
    }

    /** @return iterable<string, array{int}> */
    public static function invalidMinutes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
    }

    /** Durations are compared by value. */
    public function test_compares_by_value(): void
    {
        $this->assertTrue(Duration::ofMinutes(30)->equals(Duration::ofMinutes(30)));
        $this->assertFalse(Duration::ofMinutes(30)->equals(Duration::ofMinutes(35)));
    }
}
