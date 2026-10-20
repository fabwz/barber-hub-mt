<?php

declare(strict_types=1);

namespace Src\Catalog\Closures\Application\UseCases;

use DateTimeImmutable;
use Src\Catalog\Closures\Domain\Contracts\ClosureRepository;
use Src\Catalog\Closures\Domain\Entities\Closure;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureDate;

final readonly class ListClosures
{
    /** Receive the closure repository port. */
    public function __construct(private ClosureRepository $closures) {}

    /**
     * List the closures of the current barbershop from today onward, ordered by date.
     *
     * @return list<Closure>
     */
    public function execute(DateTimeImmutable $now): array
    {
        return $this->closures->from(ClosureDate::fromMoment($now));
    }
}
