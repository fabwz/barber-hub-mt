<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Ai;

use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Src\Reservations\Reservations\Application\Contracts\ReservationExtractor;
use Src\Reservations\Reservations\Application\DTOs\ExtractedReservation;
use Src\Reservations\Reservations\Application\DTOs\ExtractionContext;
use Src\Reservations\Reservations\Application\Exceptions\ExtractionInvalidException;
use Src\Reservations\Reservations\Application\Exceptions\ExtractorFailure;
use Src\Reservations\Reservations\Application\Exceptions\ExtractorUnavailableException;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Infrastructure\Http\Middleware\AssignRequestId;
use Throwable;

/**
 * A circuit breaker around the language provider, behind the same port (IA-05, RNF-11): when the provider is down or the free
 * quota is over, the system stops calling it, answers "unavailable" at once and tries again by itself.
 *
 * - Closed: every call goes through. `failures` consecutive failures of availability (timeout, connection, 429, 5xx) inside
 *   `window_seconds` open the circuit. A 401 or a 403 opens it at once and is logged as critical: the key must be fixed.
 * - Open: for `open_seconds` (or the `Retry-After` of the provider, if longer) nothing is called; the answer is immediate and
 *   carries the seconds that are left.
 * - Half-open: after that, ONE call is let through (the probe). If it works the circuit closes; if it fails it opens again.
 *
 * An invalid answer is not a failure of availability (the provider did answer) and our own misconfiguration says nothing about
 * the provider; a missing key never reaches it. The state is shared between processes in a cache store; if the cache fails the
 * circuit behaves as closed and the request is never broken. Time comes from the `Clock`. Every transition is logged with its
 * reason and duration, never with the phrase, the answer or the key.
 */
final readonly class CircuitBreakerReservationExtractor implements ReservationExtractor
{
    /** The longest the circuit stays open at once, whatever the provider asks. */
    private const int MAX_OPEN_SECONDS = 3600;

    /** Receive the extractor behind the circuit, its state, the clock and the limits of config/ai.php. */
    public function __construct(
        private ReservationExtractor $extractor,
        private AiCircuitStore $store,
        private Clock $clock,
        private string $provider,
        private int $failureThreshold,
        private int $windowSeconds,
        private int $openSeconds,
    ) {}

    public function extract(string $phrase, ExtractionContext $context): ExtractedReservation
    {
        $now = $this->clock->now()->getTimestamp();
        $state = $this->guard(fn (): ?array => $this->store->state(), null);
        $probing = false;

        if ($state !== null) {
            if ($now < $state['open_until']) {
                throw ExtractorUnavailableException::circuitOpen($state['open_until'] - $now);
            }

            if (! $this->guard(fn (): bool => $this->store->claimProbe(), true)) {
                throw ExtractorUnavailableException::circuitOpen(1);
            }

            $probing = true;
            $this->transition('open', 'half_open', 'open_time_elapsed', ['waited_seconds' => $now - $state['opened_at']]);
        }

        try {
            $extracted = $this->extractor->extract($phrase, $context);
        } catch (ExtractorUnavailableException $exception) {
            throw $this->unavailable($exception, $now, $probing);
        } catch (ExtractionInvalidException $exception) {
            $this->answered($now, $probing, $state);

            throw $exception;
        } finally {
            if ($probing) {
                $this->guard(fn () => $this->store->releaseProbe(), null);
            }
        }

        $this->answered($now, $probing, $state);

        return $extracted;
    }

    /** The provider failed: count it, and open the circuit if it is time. Returns what the caller must throw. */
    private function unavailable(ExtractorUnavailableException $exception, int $now, bool $probing): ExtractorUnavailableException
    {
        $from = $probing ? 'half_open' : 'closed';

        if ($exception->failure === ExtractorFailure::NotConfigured || $exception->failure === ExtractorFailure::CircuitOpen) {
            return $exception;
        }

        if ($exception->failure === ExtractorFailure::CredentialsRefused) {
            return $this->open($exception, $now, $from, 'credentials_refused', 0);
        }

        if ($probing) {
            return $this->open($exception, $now, $from, 'probe_failed', 1);
        }

        $failures = $this->guard(fn (): int => $this->store->recordFailure($now, $this->windowSeconds), 0);

        return $failures >= $this->failureThreshold
            ? $this->open($exception, $now, $from, 'consecutive_failures', $failures)
            : $exception;
    }

    /** Open the circuit and tell the caller how long it is. */
    private function open(ExtractorUnavailableException $exception, int $now, string $from, string $reason, int $failures): ExtractorUnavailableException
    {
        $seconds = min(self::MAX_OPEN_SECONDS, max($this->openSeconds, $exception->retryAfterSeconds ?? 0));
        $stored = $this->guard(function () use ($now, $seconds, $reason): bool {
            $this->store->open($now, $now + $seconds, $reason);

            return true;
        }, false);

        if (! $stored) {
            return $exception;
        }

        $this->transition($from, 'open', $reason, ['open_seconds' => $seconds, 'failures' => $failures], $reason === 'credentials_refused' ? 'critical' : 'warning');

        return ExtractorUnavailableException::circuitOpen($seconds, $exception);
    }

    /**
     * The provider answered, with a result or with something invalid: a probe closes the circuit, and the failures stop being consecutive.
     *
     * @param  array{opened_at: int, open_until: int, reason: string}|null  $state
     */
    private function answered(int $now, bool $probing, ?array $state): void
    {
        if ($probing && $state !== null) {
            $this->guard(fn () => $this->store->close(), null);
            $this->transition('half_open', 'closed', 'probe_succeeded', ['open_duration_seconds' => $now - $state['opened_at']]);

            return;
        }

        $this->guard(fn () => $this->store->resetFailures(), null);
    }

    /**
     * Run something with the cache; if the cache fails the circuit behaves as closed and the request goes on.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @param  T  $fallback
     * @return T
     */
    private function guard(Closure $operation, mixed $fallback): mixed
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            // Only the class: the message may carry the host or credentials.
            Log::warning('The state of the AI circuit is not available: it behaves as closed.', ['provider' => $this->provider, 'exception' => $exception::class]);

            return $fallback;
        }
    }

    /**
     * Log one change of state.
     *
     * @param  array<string, int|string>  $extra
     */
    private function transition(string $from, string $to, string $reason, array $extra = [], string $level = 'info'): void
    {
        Log::log($level, "The AI circuit changed from {$from} to {$to}.", [
            'provider' => $this->provider,
            'from' => $from,
            'to' => $to,
            'reason' => $reason,
            ...$extra,
            'request_id' => Context::get(AssignRequestId::CONTEXT_KEY),
        ]);
    }
}
