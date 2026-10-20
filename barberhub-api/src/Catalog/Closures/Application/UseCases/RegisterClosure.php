<?php

declare(strict_types=1);

namespace Src\Catalog\Closures\Application\UseCases;

use DateTimeImmutable;
use Src\Catalog\Closures\Application\DTOs\RegisterClosureCommand;
use Src\Catalog\Closures\Domain\Contracts\ClosureRepository;
use Src\Catalog\Closures\Domain\Entities\Closure;
use Src\Catalog\Closures\Domain\Exceptions\ClosureAlreadyExistsException;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureDate;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureReason;

final readonly class RegisterClosure
{
    /** Receive the closure repository port. */
    public function __construct(private ClosureRepository $closures) {}

    /** Register a closure of the current barbershop for today or a later date; a date closes once (HO-01). */
    public function execute(RegisterClosureCommand $command, DateTimeImmutable $now): Closure
    {
        $date = new ClosureDate($command->date);

        if ($this->closures->onDate($date) !== null) {
            throw ClosureAlreadyExistsException::create();
        }

        $closure = Closure::create(
            $this->closures->nextId(),
            $date,
            $command->reason === null ? null : new ClosureReason($command->reason),
            ClosureDate::fromMoment($now),
        );

        $this->closures->save($closure);

        return $closure;
    }
}
