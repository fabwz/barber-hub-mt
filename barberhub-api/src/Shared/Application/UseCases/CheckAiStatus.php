<?php

declare(strict_types=1);

namespace Src\Shared\Application\UseCases;

use Src\Shared\Domain\AiStatus;
use Src\Shared\Domain\Contracts\AiAvailability;

final readonly class CheckAiStatus
{
    public function __construct(private AiAvailability $ai) {}

    /** Tell the state of the language provider; it is informative and never part of the general health (RNF-11). */
    public function execute(): AiStatus
    {
        return $this->ai->status();
    }
}
