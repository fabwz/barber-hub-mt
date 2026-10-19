<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Contracts;

interface QueueHealth
{
    /** Tell whether the queue backend can be reached. */
    public function isAvailable(): bool;
}
