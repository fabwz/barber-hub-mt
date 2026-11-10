<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Notices;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Payments\Payments\Application\Contracts\ProcessedNotices;
use Src\Payments\Payments\Application\DTOs\GatewayNotice;

/** Records the applied notices in `webhook_events`, whose unique key (gateway, event_id) turns a repeated notice into a no-op (PA-05). */
final readonly class DatabaseProcessedNotices implements ProcessedNotices
{
    /** Insert the event; a copy that is already there, committed or being written by a simultaneous transaction, inserts nothing. */
    public function register(GatewayNotice $notice, string $paymentId, DateTimeImmutable $now): bool
    {
        $inserted = DB::affectingStatement(
            'insert into webhook_events (id, gateway, event_id, event_type, payment_id, received_at, processed_at) values (?, ?, ?, ?, ?, ?, ?) on conflict (gateway, event_id) do nothing',
            [(string) Str::uuid7(), $notice->provider->value, $notice->eventId, $notice->eventType, $paymentId, $now, $now],
        );

        return $inserted === 1;
    }
}
