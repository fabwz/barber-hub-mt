<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Infrastructure\Barbershops;

use Src\Catalog\Barbershops\Application\UseCases\GetBarbershopFeatures;
use Src\Waitlist\Entries\Application\Contracts\WaitlistSettings;

final readonly class BarbershopsWaitlistSettings implements WaitlistSettings
{
    /** Receive the Barbershops use case that gives the switches. */
    public function __construct(private GetBarbershopFeatures $getFeatures) {}

    /** Tell whether the current barbershop has the waitlist on, through the Barbershops module. */
    public function isEnabled(): bool
    {
        return $this->getFeatures->execute()->waitlistEnabled;
    }
}
