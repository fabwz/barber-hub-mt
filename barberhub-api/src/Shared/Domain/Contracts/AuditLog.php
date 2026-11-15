<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Contracts;

use Src\Shared\Domain\ValueObjects\AuditEntry;

interface AuditLog
{
    /**
     * Append the entry to the technical record of the current barbershop (SE-04). It writes in the transaction that is open, so
     * a change that is rolled back leaves no entry; the record is never read or changed through this port.
     */
    public function record(AuditEntry $entry): void;
}
