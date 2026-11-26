<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\Contracts;

use DateTimeImmutable;
use Src\Shared\Monitoring\Application\DTOs\HealthCheckEntry;
use Src\Shared\Monitoring\Application\DTOs\HealthTotals;

/** Reads the answers of the health route that were recorded. */
interface HealthChecksReader
{
    /** The latest answer, or null when none was ever recorded. */
    public function latest(): ?HealthCheckEntry;

    /**
     * The answers that were not 200 since the instant, the latest first.
     *
     * @return list<HealthCheckEntry>
     */
    public function failuresSince(DateTimeImmutable $since, int $limit): array;

    /** How many times the route was asked since the instant, and how many it answered 200. */
    public function totalsSince(DateTimeImmutable $since): HealthTotals;
}
