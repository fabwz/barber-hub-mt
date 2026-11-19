<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class EntryNotFoundException extends NotFoundException
{
    /** Build the exception for a row the person cannot reach: it does not exist or belongs to another barbershop or customer. It reads like any missing resource (MT-03, CL-04). */
    public static function create(): self
    {
        return new self('problems.no-encontrado.detail');
    }
}
