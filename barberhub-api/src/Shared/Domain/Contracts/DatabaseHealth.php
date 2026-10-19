<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Contracts;

interface DatabaseHealth
{
    /** Tell whether the database answers queries. */
    public function isAvailable(): bool;
}
