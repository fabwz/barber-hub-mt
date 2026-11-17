<?php

declare(strict_types=1);

namespace Src\Payments\Income\Application\DTOs;

/** The figures of one day of the report, in colones; the net is what was charged online less what was refunded (it can be negative). */
final readonly class IncomeDay
{
    /** Hold the day as `YYYY-MM-DD` and its figures. */
    public function __construct(
        public string $date,
        public int $charged,
        public int $refunded,
        public int $net,
        public int $vouchersSold,
        public int $servicesAttended,
    ) {}
}
