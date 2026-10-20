<?php

declare(strict_types=1);

namespace Src\Catalog\Closures\Application\UseCases;

use Src\Catalog\Closures\Domain\Contracts\ClosureRepository;
use Src\Catalog\Closures\Domain\Exceptions\ClosureNotFoundException;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureId;

final readonly class DeleteClosure
{
    /** Receive the closure repository port. */
    public function __construct(private ClosureRepository $closures) {}

    /** Remove a closure of the current barbershop so it opens again that day. */
    public function execute(string $closureId): void
    {
        $closure = $this->closures->find(new ClosureId($closureId)) ?? throw ClosureNotFoundException::create();

        $this->closures->delete($closure);
    }
}
