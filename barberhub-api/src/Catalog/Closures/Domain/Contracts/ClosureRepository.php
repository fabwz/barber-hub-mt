<?php

declare(strict_types=1);

namespace Src\Catalog\Closures\Domain\Contracts;

use Src\Catalog\Closures\Domain\Entities\Closure;
use Src\Catalog\Closures\Domain\Exceptions\ClosureAlreadyExistsException;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureDate;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureId;

interface ClosureRepository
{
    /** Generate the identity for a new closure. */
    public function nextId(): ClosureId;

    /**
     * Persist a new closure in the current barbershop.
     *
     * @throws ClosureAlreadyExistsException
     */
    public function save(Closure $closure): void;

    /** Find a closure of the current barbershop. */
    public function find(ClosureId $id): ?Closure;

    /** Find the closure of the current barbershop on a date, if any. */
    public function onDate(ClosureDate $date): ?Closure;

    /**
     * List the closures of the current barbershop from a date onward, ordered by date.
     *
     * @return list<Closure>
     */
    public function from(ClosureDate $date): array;

    /** Remove a closure. */
    public function delete(Closure $closure): void;
}
