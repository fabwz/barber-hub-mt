<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\InvalidValueException;

final class UnknownServiceException extends InvalidValueException
{
    /** Build the exception for the service at a position of the list that the barbershop does not have (CO-01). */
    public static function at(int $index): self
    {
        return new self('collaborators.unknown_service', ['index' => $index]);
    }

    /** Position of the rejected service in the list, counted from zero. */
    public function index(): int
    {
        return (int) $this->parameters()['index'];
    }
}
