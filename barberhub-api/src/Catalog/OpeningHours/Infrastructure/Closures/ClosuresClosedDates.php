<?php

declare(strict_types=1);

namespace Src\Catalog\OpeningHours\Infrastructure\Closures;

use DateTimeImmutable;
use Src\Catalog\Closures\Application\UseCases\IsClosedOn;
use Src\Catalog\OpeningHours\Application\Contracts\ClosedDates;

final readonly class ClosuresClosedDates implements ClosedDates
{
    /** Receive the Closures use case that looks up a closure by date. */
    public function __construct(private IsClosedOn $isClosedOn) {}

    /** Ask the Closures module whether the barbershop is closed on the date of the moment. */
    public function isClosedOn(DateTimeImmutable $date): bool
    {
        return $this->isClosedOn->execute($date);
    }
}
