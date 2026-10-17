<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Contracts;

use DateTimeImmutable;

interface Clock
{
    /** Current instant, in the application time zone. */
    public function now(): DateTimeImmutable;
}
