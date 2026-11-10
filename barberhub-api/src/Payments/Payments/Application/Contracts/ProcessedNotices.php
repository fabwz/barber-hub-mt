<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Contracts;

use DateTimeImmutable;
use Src\Payments\Payments\Application\DTOs\GatewayNotice;

/** The notices already applied, once each (PA-05). The record belongs to the transaction of its effect. */
interface ProcessedNotices
{
    /**
     * Record the notice as applied to the payment. It answers false when the provider's event was already recorded, and
     * then the caller does nothing. With a simultaneous copy it waits for the other transaction to end.
     */
    public function register(GatewayNotice $notice, string $paymentId, DateTimeImmutable $now): bool;
}
