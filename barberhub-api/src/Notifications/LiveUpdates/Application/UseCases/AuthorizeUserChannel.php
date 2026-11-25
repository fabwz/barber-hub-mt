<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Application\UseCases;

use Src\Notifications\LiveUpdates\Application\Exceptions\ChannelNotFoundException;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class AuthorizeUserChannel
{
    /**
     * Let a person listen to their own channel and nobody else (RF-35, NO-08). The channel of another account, one that does not exist and a
     * name that is not an identifier are the same not found as any missing resource, so nobody can tell whose accounts exist (UP-06). The
     * identifiers are read in any case, as the routes do.
     *
     * @throws ChannelNotFoundException
     */
    public function execute(string $userId, string $channelUserId): void
    {
        try {
            $own = (new UserId($userId))->value === (new UserId(strtolower($channelUserId)))->value;
        } catch (InvalidValueException) {
            throw ChannelNotFoundException::create();
        }

        if (! $own) {
            throw ChannelNotFoundException::create();
        }
    }
}
