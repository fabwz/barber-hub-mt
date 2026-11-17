<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain\ValueObjects;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\ReportPeriod;

final class ReportPeriodTest extends TestCase
{
    /** The range runs from 00:00 of the first day to 00:00 of the day after the last, in Costa Rica. */
    public function test_covers_whole_days_of_costa_rica(): void
    {
        $period = ReportPeriod::between('2030-03-01', '2030-03-31');

        $this->assertSame('2030-03-01T00:00:00-06:00', $period->from->format('c'));
        $this->assertSame('2030-04-01T00:00:00-06:00', $period->until->format('c'));
        $this->assertSame('2030-03-01', $period->firstDate);
        $this->assertSame('2030-03-31', $period->lastDate);
    }

    /** A single day is a valid range of one day. */
    public function test_accepts_a_single_day(): void
    {
        $period = ReportPeriod::between('2030-03-01', '2030-03-01');

        $this->assertSame(86400, $period->until->getTimestamp() - $period->from->getTimestamp());
    }

    /** A leap year is 366 days and is the longest range. */
    public function test_accepts_366_days_and_refuses_367(): void
    {
        $this->assertSame('2028-12-31', ReportPeriod::between('2028-01-01', '2028-12-31')->lastDate);

        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('shared.report_period_too_long');
        ReportPeriod::between('2028-01-01', '2029-01-01');
    }

    /** The first day cannot come after the last. */
    public function test_refuses_a_reversed_range(): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('shared.report_period_reversed');
        ReportPeriod::between('2030-03-02', '2030-03-01');
    }

    /** Text that is not a real day written as YYYY-MM-DD is refused. */
    #[DataProvider('invalidDates')]
    public function test_refuses_a_date_that_is_not_a_real_day(string $date): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('shared.report_period_invalid_date');
        ReportPeriod::between($date, '2030-12-31');
    }

    /**
     * Texts that are not days.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'empty' => [''];
        yield 'day that does not exist' => ['2030-02-30'];
        yield 'one digit month' => ['2030-3-01'];
        yield 'another order' => ['01-03-2030'];
        yield 'with a time' => ['2030-03-01 10:00'];
    }
}
