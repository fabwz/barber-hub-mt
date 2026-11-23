<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Contracts;

/**
 * The identifier that ties together everything one execution does: a request, a queued job or a scheduled task (RNF-25). When the
 * origin is a request it is the `X-Request-Id` of that request.
 */
interface CorrelationContext
{
    /** The identifier of the current execution; one is generated and kept if there is none yet. */
    public function current(): string;

    /** Use this identifier (a UUID) for the rest of the execution, for the one that came with the request or the job. */
    public function adopt(string $correlationId): void;

    /** Start a new identifier for a new unit of work with no request behind it, and return it. */
    public function begin(): string;
}
