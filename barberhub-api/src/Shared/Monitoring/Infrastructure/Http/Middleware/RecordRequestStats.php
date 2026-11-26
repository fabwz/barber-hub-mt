<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Src\Shared\Monitoring\Application\UseCases\PurgeExpiredMonitoringData;
use Src\Shared\Monitoring\Application\UseCases\RecordRequest;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Adds every request to the statistics of its minute after the response was sent (RNF-15, ADR 0013). It measures the duration of the request,
 * reads the PATTERN of the route and the CLASS of the exception that `RememberRequestException` left in the request, and nothing else: no URL, no
 * user, no body. A failure here is logged (its class only) and never changes the answer. It does nothing unless `monitoring.enabled` is on.
 */
final readonly class RecordRequestStats
{
    private const string DURATION = 'monitoring.duration_ms';

    public function __construct(private RecordRequest $record, private PurgeExpiredMonitoringData $purge) {}

    /** Measure the request. */
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $response = $next($request);
        $request->attributes->set(self::DURATION, intdiv((int) (hrtime(true) - $started), 1_000_000));

        return $response;
    }

    /** Record the request once the response has gone out. */
    public function terminate(Request $request, Response $response): void
    {
        if (config('monitoring.enabled') !== true) {
            return;
        }

        try {
            $route = $request->route();
            $exception = $request->attributes->get(RememberRequestException::ATTRIBUTE);

            $this->record->execute(
                $request->method(),
                $route instanceof Route ? $route->uri() : null,
                $response->getStatusCode(),
                is_string($exception) ? $exception : null,
                (int) $request->attributes->get(self::DURATION, 0),
            );
            $this->purge->execute();
        } catch (Throwable $e) {
            // Only the class: the message may carry the host or credentials.
            Log::warning('Monitoring: the request was not recorded.', ['exception' => $e::class]);
        }
    }
}
