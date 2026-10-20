<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Exceptions;

final class FileOutsideBarbershopException extends NotFoundException
{
    /** Build the exception for a path that leaves the folder of the current barbershop (MT-05). */
    public static function create(): self
    {
        return new self('shared.file_not_found');
    }
}
