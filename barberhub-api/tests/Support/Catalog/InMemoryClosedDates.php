<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use DateTimeImmutable;
use Src\Catalog\Closures\Application\UseCases\IsClosedOn;
use Src\Catalog\OpeningHours\Application\Contracts\ClosedDates;

/** The closed dates port answered from the in-memory closures, as the adapter does from the module. */
final readonly class InMemoryClosedDates implements ClosedDates
{
    public function __construct(private InMemoryClosureRepository $closures) {}

    /** Tell whether the closures hold the date of the moment. */
    public function isClosedOn(DateTimeImmutable $date): bool
    {
        return new IsClosedOn($this->closures)->execute($date);
    }
}
