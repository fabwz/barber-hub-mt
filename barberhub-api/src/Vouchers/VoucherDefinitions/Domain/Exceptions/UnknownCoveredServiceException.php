<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\InvalidValueException;

final class UnknownCoveredServiceException extends InvalidValueException
{
    /** Build the exception for the service at a position of the list that the barbershop does not have (BO-01, MT-04). */
    public static function at(int $index): self
    {
        return new self('vouchers.unknown_service', ['index' => $index]);
    }

    /** Position of the rejected service in the list, counted from zero. */
    public function index(): int
    {
        return (int) $this->parameters()['index'];
    }
}
