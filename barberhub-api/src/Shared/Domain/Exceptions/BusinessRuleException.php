<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Exceptions;

abstract class BusinessRuleException extends DomainException
{
    /** Stable identifier of the broken rule; the API publishes it as the type of the problem. */
    abstract public function rule(): string;
}
