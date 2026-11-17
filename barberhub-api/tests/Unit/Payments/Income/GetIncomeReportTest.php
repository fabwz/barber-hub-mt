<?php

declare(strict_types=1);

namespace Tests\Unit\Payments\Income;

use PHPUnit\Framework\TestCase;
use Src\Payments\Income\Application\Contracts\AttendedServices;
use Src\Payments\Income\Application\Contracts\OnlinePaymentTotals;
use Src\Payments\Income\Application\Contracts\SoldVouchers;
use Src\Payments\Income\Application\DTOs\DayAmount;
use Src\Payments\Income\Application\UseCases\GetIncomeReport;
use Src\Shared\Domain\ValueObjects\ReportPeriod;

final class GetIncomeReportTest extends TestCase
{
    /** The three figures stay apart, the net is the charge less the refund, and the days merge the four sources in order. */
    public function test_keeps_the_three_figures_apart_and_merges_the_days(): void
    {
        $report = $this->useCase(
            charged: [new DayAmount('2030-03-05', 2, 8000), new DayAmount('2030-03-10', 1, 4000)],
            refunded: [new DayAmount('2030-03-12', 1, 4000)],
            vouchers: [new DayAmount('2030-03-01', 1, 30000)],
            services: [new DayAmount('2030-03-05', 2, 14000)],
        )->execute('2030-03-01', '2030-03-31');

        $this->assertSame([12000, 4000, 8000], [$report->charged, $report->refunded, $report->net]);
        $this->assertSame([30000, 1], [$report->vouchersTotal, $report->vouchersCount]);
        $this->assertSame([14000, 2], [$report->servicesTotal, $report->servicesCount]);
        $this->assertSame(['2030-03-01', '2030-03-05', '2030-03-10', '2030-03-12'], array_column(array_map(fn ($day): array => (array) $day, $report->days), 'date'));
        $this->assertSame([8000, 14000], [$report->days[1]->charged, $report->days[1]->servicesAttended]);
        $this->assertSame(-4000, $report->days[3]->net);
    }

    /** A range without data is a report of zeros with no days, not an error. */
    public function test_a_range_without_data_is_all_zeros(): void
    {
        $report = $this->useCase([], [], [], [])->execute('2030-03-01', '2030-03-31');

        $this->assertSame([0, 0, 0, 0, 0, 0, 0], [$report->charged, $report->refunded, $report->net, $report->vouchersTotal, $report->vouchersCount, $report->servicesTotal, $report->servicesCount]);
        $this->assertSame([], $report->days);
    }

    /**
     * Build the use case over fixed answers of its four sources.
     *
     * @param  list<DayAmount>  $charged
     * @param  list<DayAmount>  $refunded
     * @param  list<DayAmount>  $vouchers
     * @param  list<DayAmount>  $services
     */
    private function useCase(array $charged, array $refunded, array $vouchers, array $services): GetIncomeReport
    {
        $payments = new class($charged, $refunded) implements OnlinePaymentTotals
        {
            /**
             * @param  list<DayAmount>  $charged
             * @param  list<DayAmount>  $refunded
             */
            public function __construct(private array $charged, private array $refunded) {}

            public function chargedIn(ReportPeriod $period): array
            {
                return $this->charged;
            }

            public function refundedIn(ReportPeriod $period): array
            {
                return $this->refunded;
            }
        };
        $sold = new class($vouchers) implements SoldVouchers
        {
            /** @param  list<DayAmount>  $days */
            public function __construct(private array $days) {}

            public function in(ReportPeriod $period): array
            {
                return $this->days;
            }
        };
        $attended = new class($services) implements AttendedServices
        {
            /** @param  list<DayAmount>  $days */
            public function __construct(private array $days) {}

            public function in(ReportPeriod $period): array
            {
                return $this->days;
            }
        };

        return new GetIncomeReport($payments, $sold, $attended);
    }
}
