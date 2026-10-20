<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class PublicLinkTakenException extends BusinessRuleException
{
    /** Build the exception for a link that another barbershop already has (MT-07). */
    public static function create(): self
    {
        return new self('barbershops.public_link_taken');
    }

    /** The link clashes with the stored one. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
