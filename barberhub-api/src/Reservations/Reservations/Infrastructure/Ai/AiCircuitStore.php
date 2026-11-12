<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Ai;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;

/**
 * Where the circuit breaker of the language provider keeps what the processes must share: whether the circuit is open (and
 * until when), the recent failures and the claim of the single probe call. Every method may throw if the cache is down; the
 * callers decide what that means (the circuit then behaves as closed). Times are Unix seconds of the application `Clock`.
 */
final readonly class AiCircuitStore
{
    /** How long a stored state may live without being touched; a circuit nobody probes anymore heals by itself. */
    private const int STATE_TTL_SECONDS = 86_400;

    /** How long the claim of the probe lasts if its process dies: the provider is called for 10 s at most (IA-05). */
    private const int PROBE_TTL_SECONDS = 30;

    /** Receive the cache, the name of its store (null is the default one) and the provider the state belongs to. */
    public function __construct(private Factory $caches, private ?string $store, private string $provider) {}

    /**
     * The open circuit, or null when it is closed. After `open_until` it is still returned: it waits for its probe to close it.
     *
     * @return array{opened_at: int, open_until: int, reason: string}|null
     */
    public function state(): ?array
    {
        $state = $this->cache()->get($this->key('state'));

        if (! is_array($state) || ! is_int($state['opened_at'] ?? null) || ! is_int($state['open_until'] ?? null)) {
            return null;
        }

        return ['opened_at' => $state['opened_at'], 'open_until' => $state['open_until'], 'reason' => is_string($state['reason'] ?? null) ? $state['reason'] : 'unknown'];
    }

    /** Open the circuit from `$openedAt` until `$openUntil`; the failures counted so far are forgotten. */
    public function open(int $openedAt, int $openUntil, string $reason): void
    {
        $this->cache()->put($this->key('state'), ['opened_at' => $openedAt, 'open_until' => $openUntil, 'reason' => $reason], self::STATE_TTL_SECONDS);
        $this->cache()->forget($this->key('failures'));
    }

    /** Close the circuit. */
    public function close(): void
    {
        $this->cache()->forget($this->key('state'));
        $this->cache()->forget($this->key('failures'));
    }

    /** Count one more failure of availability inside the window that started with the first one; returns how many there are in it. */
    public function recordFailure(int $now, int $windowSeconds): int
    {
        $failures = $this->cache()->get($this->key('failures'));
        $inWindow = is_array($failures) && is_int($failures['count'] ?? null) && is_int($failures['since'] ?? null) && $now - $failures['since'] < $windowSeconds;
        $count = $inWindow ? $failures['count'] + 1 : 1;
        $since = $inWindow ? $failures['since'] : $now;

        $this->cache()->put($this->key('failures'), ['count' => $count, 'since' => $since], max(60, $windowSeconds * 2));

        return $count;
    }

    /** Forget the failures: the provider answered, so the failures are no longer consecutive. */
    public function resetFailures(): void
    {
        $this->cache()->forget($this->key('failures'));
    }

    /** Try to be the one process that makes the probe call; false when another one already is. */
    public function claimProbe(): bool
    {
        return $this->cache()->add($this->key('probe'), 1, self::PROBE_TTL_SECONDS);
    }

    /** Give the probe claim back. */
    public function releaseProbe(): void
    {
        $this->cache()->forget($this->key('probe'));
    }

    /** Claim the right to warn that the provider is not configured; false while the warning was already given (once an hour at most). */
    public function claimUnconfiguredWarning(): bool
    {
        return $this->cache()->add($this->key('unconfigured-warned'), 1, 3600);
    }

    /** The provider is configured again: the next time it is not, the warning is given again. */
    public function clearUnconfiguredWarning(): void
    {
        $this->cache()->forget($this->key('unconfigured-warned'));
    }

    private function cache(): Repository
    {
        return $this->caches->store($this->store);
    }

    private function key(string $part): string
    {
        return "ai-circuit:{$this->provider}:{$part}";
    }
}
