<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class AbsenceNotFoundException extends NotFoundException
{
    /** Build the exception for an absence missing from the barbershop. */
    public static function create(): self
    {
        return new self('absences.not_found');
    }
}
