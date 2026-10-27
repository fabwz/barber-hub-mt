<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Exceptions;

interface HasInvalidField
{
    /**
     * The JSON attribute of the API (RD-07) whose value caused the error, so the problem document reports it under `errores`
     * like a validation error of the request does.
     */
    public function field(): string;
}
