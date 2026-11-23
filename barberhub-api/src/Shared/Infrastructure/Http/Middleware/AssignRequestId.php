<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Src\Shared\Domain\Contracts\CorrelationContext;
use Symfony\Component\HttpFoundation\Response;

final readonly class AssignRequestId
{
    public const string HEADER = 'X-Request-Id';

    /** Receive the port that keeps the identifier of the execution. */
    public function __construct(private CorrelationContext $correlation) {}

    /** Tag the request, its log entries, its queued jobs and its response with one identifier: the correlation identifier is the one of the request (RNF-25). */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolve($request);

        $this->correlation->adopt($requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    /** Reuse a well-formed incoming identifier or generate a new one. */
    private function resolve(Request $request): string
    {
        $incoming = $request->headers->get(self::HEADER);

        return is_string($incoming) && Str::isUuid($incoming)
            ? Str::lower($incoming)
            : (string) Str::uuid7();
    }
}
