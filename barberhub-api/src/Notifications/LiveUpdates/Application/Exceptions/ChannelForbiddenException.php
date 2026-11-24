<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Application\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class ChannelForbiddenException extends BusinessRuleException
{
    /** Build the exception for a person of the barbershop whose role does not allow listening to the channel (UP-02, CO-04). */
    public static function create(): self
    {
        return new self('problems.prohibido.detail');
    }

    /** The API answers 403 `prohibido`. */
    public function rule(): string
    {
        return 'prohibido';
    }
}
