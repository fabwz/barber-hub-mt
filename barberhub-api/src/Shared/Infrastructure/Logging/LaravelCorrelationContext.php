<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Logging;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Src\Shared\Domain\Contracts\CorrelationContext;

/**
 * Keeps the identifier in the context of Laravel: every log entry of the execution carries it without being told, and a queued job
 * takes it along in its payload.
 */
final readonly class LaravelCorrelationContext implements CorrelationContext
{
    public const string KEY = 'correlation_id';

    /** Return the identifier of the execution, generating one when there is none. */
    public function current(): string
    {
        $current = Context::get(self::KEY);

        return is_string($current) ? $current : $this->begin();
    }

    /** Keep the identifier, in lowercase, for the rest of the execution. */
    public function adopt(string $correlationId): void
    {
        Context::add(self::KEY, Str::lower($correlationId));
    }

    /** Generate a new identifier and keep it. */
    public function begin(): string
    {
        $correlationId = (string) Str::uuid7();
        Context::add(self::KEY, $correlationId);

        return $correlationId;
    }
}
