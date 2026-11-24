<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Application\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class ChannelNotFoundException extends NotFoundException
{
    /**
     * Build the exception for a channel the person cannot reach because they hold no role in the barbershop, the barbershop does not
     * exist, an identifier is not valid or the agenda belongs to another collaborator. It reads like any missing resource (UP-06, MT-03).
     */
    public static function create(): self
    {
        return new self('problems.no-encontrado.detail');
    }
}
