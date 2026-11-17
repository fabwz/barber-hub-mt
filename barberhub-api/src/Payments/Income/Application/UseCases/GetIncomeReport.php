<?php

declare(strict_types=1);

namespace Src\Payments\Income\Application\UseCases;

use Src\Payments\Income\Application\Contracts\AttendedServices;
use Src\Payments\Income\Application\Contracts\OnlinePaymentTotals;
use Src\Payments\Income\Application\Contracts\SoldVouchers;
use Src\Payments\Income\Application\DTOs\DayAmount;
use Src\Payments\Income\Application\DTOs\IncomeDay;
use Src\Payments\Income\Application\DTOs\IncomeReport;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\ReportPeriod;

final readonly class GetIncomeReport
{
    /** Receive the port for the payments and refunds, which are Payments' own, and the ones of the vouchers sold and the services attended. */
    public function __construct(
        private OnlinePaymentTotals $payments,
        private SoldVouchers $vouchers,
        private AttendedServices $services,
    ) {}

    /**
     * Build the income report of the current barbershop for a range of days, both included (RF-25, PA-11): what was charged online
     * net of refunds, the vouchers sold and, apart, the value of the services attended. The three figures are never added together,
     * and the balance paid in the barbershop appears nowhere (PA-10). A range without data answers zeros and no days, not an error. Only
     * the owner reaches it, and every source reads the barbershop of the route only (MT-02, CO-04).
     *
     * @throws InvalidValueException when the dates are not real days, are reversed or span more than a year
     */
    public function execute(string $from, string $to): IncomeReport
    {
        $period = ReportPeriod::between($from, $to);
        $charged = $this->byDay($this->payments->chargedIn($period));
        $refunded = $this->byDay($this->payments->refundedIn($period));
        $vouchers = $this->byDay($this->vouchers->in($period));
        $services = $this->byDay($this->services->in($period));

        $dates = array_unique([...array_keys($charged), ...array_keys($refunded), ...array_keys($vouchers), ...array_keys($services)]);
        sort($dates);

        $days = array_map(fn (string $date): IncomeDay => new IncomeDay(
            $date,
            $charged[$date]->total ?? 0,
            $refunded[$date]->total ?? 0,
            ($charged[$date]->total ?? 0) - ($refunded[$date]->total ?? 0),
            $vouchers[$date]->total ?? 0,
            $services[$date]->total ?? 0,
        ), $dates);

        $grossTotal = self::sum($charged);
        $refundedTotal = self::sum($refunded);

        return new IncomeReport(
            $period->firstDate,
            $period->lastDate,
            $grossTotal,
            $refundedTotal,
            $grossTotal - $refundedTotal,
            self::sum($vouchers),
            self::count($vouchers),
            self::sum($services),
            self::count($services),
            $days,
        );
    }

    /**
     * Index the days of a source by their date.
     *
     * @param  list<DayAmount>  $days
     * @return array<string, DayAmount>
     */
    private function byDay(array $days): array
    {
        $indexed = [];

        foreach ($days as $day) {
            $indexed[$day->date] = $day;
        }

        return $indexed;
    }

    /**
     * Sum of the amounts of the days.
     *
     * @param  array<string, DayAmount>  $days
     */
    private static function sum(array $days): int
    {
        return array_sum(array_map(fn (DayAmount $day): int => $day->total, $days));
    }

    /**
     * Sum of the entries of the days.
     *
     * @param  array<string, DayAmount>  $days
     */
    private static function count(array $days): int
    {
        return array_sum(array_map(fn (DayAmount $day): int => $day->count, $days));
    }
}
