<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\DTOs;

/** The requests that ended in an exception of one class, in one route, with one class of status. */
final readonly class ErrorGroup
{
    public function __construct(
        public string $exceptionClass,
        public string $method,
        public string $route,
        public int $statusClass,
        public int $requests,
    ) {}
}
