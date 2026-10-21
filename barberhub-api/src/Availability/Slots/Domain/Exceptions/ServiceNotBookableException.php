<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class ServiceNotBookableException extends NotFoundException
{
    /** Build the exception for a service that does not exist or is inactive (SV-04). */
    public static function create(): self
    {
        return new self('availability.service_not_bookable');
    }
}
