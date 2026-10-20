<?php

declare(strict_types=1);

namespace Src\Catalog\OpeningHours\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class TimeSlotNotFoundException extends NotFoundException
{
    /** Build the exception for a slot missing from the barbershop. */
    public static function create(): self
    {
        return new self('opening_hours.time_slot_not_found');
    }
}
