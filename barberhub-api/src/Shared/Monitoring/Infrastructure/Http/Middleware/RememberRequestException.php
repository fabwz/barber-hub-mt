<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Infrastructure\Http\Middleware;

use Illuminate\Http\Request;
use Throwable;

/**
 * A render callback of the exception handler that only takes note: it leaves the CLASS of the exception in the request (never its message or its
 * trace) for `RecordRequestStats`, and returns null so the next callback, the problem+json renderer, still answers.
 */
final class RememberRequestException
{
    public const string ATTRIBUTE = 'monitoring.exception';

    public function __invoke(Throwable $exception, Request $request): null
    {
        $request->attributes->set(self::ATTRIBUTE, $exception::class);

        return null;
    }
}
