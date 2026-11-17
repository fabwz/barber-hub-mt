<?php

declare(strict_types=1);

namespace Src\Payments\Income\Application\DTOs;

/**
 * The income of a barbershop over a range of days (RF-25): the three figures stay apart and are never added into one (PA-11).
 * Amounts are colones; the counts are the number of vouchers sold and of services attended.
 */
final readonly class IncomeReport
{
    /**
     * @param  list<IncomeDay>  $days  Only the days with some figure, the earliest first.
     */
    public function __construct(
        public string $from,
        public string $to,
        public int $charged,
        public int $refunded,
        public int $net,
        public int $vouchersTotal,
        public int $vouchersCount,
        public int $servicesTotal,
        public int $servicesCount,
        public array $days,
    ) {}
}
