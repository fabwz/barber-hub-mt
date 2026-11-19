<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class ServiceNotFoundException extends NotFoundException
{
    /** Build the exception for a service that does not exist, is inactive or belongs to another barbershop; it reads like any missing resource (MT-03). */
    public static function create(): self
    {
        return new self('problems.no-encontrado.detail');
    }
}
