<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Application;

/**
 * The name of the private channel of a person, without the `private-` prefix that the transport adds (RF-35). It carries what is meant for that
 * person alone, such as the offers of the waitlist, and only they may listen to it. The identifier is the one of their account, which the
 * server reads from a membership of its own database, never from anything a person sent.
 */
final class UserChannels
{
    /** Name of the channel of one account. */
    public static function ofUser(string $userId): string
    {
        return "usuario.{$userId}";
    }
}
