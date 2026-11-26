<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Domain\Contracts;

use DateTimeImmutable;

interface MonitoringRetention
{
    /**
     * Delete a bounded batch of what is older than the limit, at most once an hour (with a lock, so two requests never purge together).
     * A scheduler can replace the opportunistic call that makes it today (#117).
     */
    public function purgeBefore(DateTimeImmutable $limit): void;
}
