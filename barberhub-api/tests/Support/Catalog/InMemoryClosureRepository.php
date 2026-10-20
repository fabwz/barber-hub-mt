<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use Src\Catalog\Closures\Domain\Contracts\ClosureRepository;
use Src\Catalog\Closures\Domain\Entities\Closure;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureDate;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureId;

final class InMemoryClosureRepository implements ClosureRepository
{
    /** @var array<string, Closure> */
    private array $closures = [];

    private int $sequence = 0;

    /** Generate a predictable identity. */
    public function nextId(): ClosureId
    {
        return new ClosureId(sprintf('0192f0c4-0000-7000-8000-%012d', ++$this->sequence));
    }

    /** Store the closure. */
    public function save(Closure $closure): void
    {
        $this->closures[$closure->id->value] = $closure;
    }

    /** Find a closure by its identity. */
    public function find(ClosureId $id): ?Closure
    {
        return $this->closures[$id->value] ?? null;
    }

    /** Find the closure of a date. */
    public function onDate(ClosureDate $date): ?Closure
    {
        return array_find($this->closures, fn (Closure $closure): bool => $closure->date->value === $date->value);
    }

    /**
     * List the closures from a date onward ordered by date.
     *
     * @return list<Closure>
     */
    public function from(ClosureDate $date): array
    {
        $closures = array_values(array_filter($this->closures, fn (Closure $closure): bool => ! $closure->date->isBefore($date)));
        usort($closures, fn (Closure $a, Closure $b): int => $a->date->value <=> $b->date->value);

        return $closures;
    }

    /** Remove the closure. */
    public function delete(Closure $closure): void
    {
        unset($this->closures[$closure->id->value]);
    }
}
