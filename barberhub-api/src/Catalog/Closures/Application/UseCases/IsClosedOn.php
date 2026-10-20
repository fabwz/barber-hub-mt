<?php

declare(strict_types=1);

namespace Src\Catalog\Closures\Application\UseCases;

use DateTimeImmutable;
use Src\Catalog\Closures\Domain\Contracts\ClosureRepository;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureDate;

final readonly class IsClosedOn
{
    /** Receive the closure repository port. */
    public function __construct(private ClosureRepository $closures) {}

    /** Tell whether the current barbershop has a closure on the calendar date of the moment, in its own time zone. */
    public function execute(DateTimeImmutable $date): bool
    {
        return $this->closures->onDate(ClosureDate::fromMoment($date)) !== null;
    }
}
