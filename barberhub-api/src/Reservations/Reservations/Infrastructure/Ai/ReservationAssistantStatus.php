<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Ai;

use Illuminate\Support\Facades\Log;
use Src\Shared\Domain\AiStatus;
use Src\Shared\Domain\Contracts\AiAvailability;
use Throwable;

/**
 * The state of the language provider for the health route: not configured without key or model, degraded while the circuit is
 * open or waiting for its probe, available otherwise. It reads only the configuration and the circuit; it never calls the provider.
 * The health route shows "not configured" as degraded, so this is where the operator is told, with a warning that is given once per
 * change of state and not on every health check, and that names no key or value.
 */
final readonly class ReservationAssistantStatus implements AiAvailability
{
    /** Receive the circuit state and whether the provider has a key and a model. */
    public function __construct(private AiCircuitStore $store, private bool $configured) {}

    public function status(): AiStatus
    {
        if (! $this->configured) {
            $this->warnOnce();

            return AiStatus::NotConfigured;
        }

        try {
            $this->store->clearUnconfiguredWarning();

            return $this->store->state() === null ? AiStatus::Available : AiStatus::Degraded;
        } catch (Throwable) {
            return AiStatus::Available;
        }
    }

    /** Warn that the AI configuration is missing the first time, and again only after it was configured and lost; never because of the cache. */
    private function warnOnce(): void
    {
        try {
            if ($this->store->claimUnconfiguredWarning()) {
                Log::warning('The AI configuration is missing: the reservation by phrase is degraded.');
            }
        } catch (Throwable) {
            // Without the cache there is no way to avoid repeating it: better silent than flooding the log.
        }
    }
}
