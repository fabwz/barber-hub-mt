<?php

declare(strict_types=1);

namespace Src\Catalog\Closures\Application\DTOs;

final readonly class RegisterClosureCommand
{
    /** Carry the data needed to register a closure. */
    public function __construct(
        public string $date,
        public ?string $reason,
    ) {}
}
