<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class PublicLinkUnavailableException extends BusinessRuleException
{
    /** Build the exception for a name whose links were all taken within the allowed attempts. */
    public static function create(): self
    {
        return new self('barbershops.public_link_unavailable');
    }

    /** Nothing in the state of the request can be changed to avoid the clash, so it is a conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
