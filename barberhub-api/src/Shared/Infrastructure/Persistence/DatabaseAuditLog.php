<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Shared\Domain\Contracts\AuditLog;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\CorrelationContext;
use Src\Shared\Domain\ValueObjects\AuditEntry;

final readonly class DatabaseAuditLog implements AuditLog
{
    /** Instants travel as text with their offset, so they never depend on the time zone of the process. */
    private const string INSTANT_FORMAT = 'Y-m-d H:i:s.uP';

    /** Receive the port that tells which barbershop the operation belongs to and the one that holds the correlation identifier. */
    public function __construct(private BarbershopContext $context, private CorrelationContext $correlation) {}

    /** Insert the entry in the table of the technical record, inside the transaction that is open. */
    public function record(AuditEntry $entry): void
    {
        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(),
            'barbershop_id' => $this->context->current()->value,
            'actor_user_id' => $entry->actor->value,
            'action' => $entry->action->value,
            'subject_type' => $entry->subjectType,
            'subject_id' => $entry->subjectId,
            'changes' => json_encode($entry->changes, JSON_THROW_ON_ERROR),
            'correlation_id' => $this->correlation->current(),
            'created_at' => $entry->at->format(self::INSTANT_FORMAT),
        ]);
    }
}
