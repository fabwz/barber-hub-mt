<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use Src\Shared\Domain\Contracts\AuditLog;
use Src\Shared\Domain\ValueObjects\AuditEntry;

final class InMemoryAuditLog implements AuditLog
{
    /** @var list<AuditEntry> */
    private array $entries = [];

    /** Keep the entry. */
    public function record(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * The entries recorded, in order.
     *
     * @return list<AuditEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
