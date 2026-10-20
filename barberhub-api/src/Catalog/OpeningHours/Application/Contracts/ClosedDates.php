<?php

declare(strict_types=1);

namespace Src\Catalog\OpeningHours\Application\Contracts;

use DateTimeImmutable;

interface ClosedDates
{
    /** Tell whether the current barbershop has a closure on the calendar date of the moment. */
    public function isClosedOn(DateTimeImmutable $date): bool;
}
