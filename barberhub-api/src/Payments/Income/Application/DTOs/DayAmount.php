<?php

declare(strict_types=1);

namespace Src\Payments\Income\Application\DTOs;

/** What one source of the report added up on one day of Costa Rica: how many entries and their sum in colones. */
final readonly class DayAmount
{
    /** Hold the day as `YYYY-MM-DD`, the number of entries and their sum. */
    public function __construct(public string $date, public int $count, public int $total) {}
}
