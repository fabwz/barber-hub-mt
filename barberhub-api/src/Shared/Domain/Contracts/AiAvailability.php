<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Contracts;

use Src\Shared\Domain\AiStatus;

interface AiAvailability
{
    /** The state of the language provider. It never throws: an unknown state is reported as available. */
    public function status(): AiStatus;
}
