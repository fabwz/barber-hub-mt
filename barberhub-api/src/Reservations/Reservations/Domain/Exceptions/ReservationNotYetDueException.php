<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Exceptions;

use DateTimeImmutable;
use Src\Shared\Domain\Exceptions\DomainException;

final class ReservationNotYetDueException extends DomainException
{
    /** Build the exception for a reservation closed before its start; it says from when it can be marked as attended (CO-05). */
    public static function beforeStart(DateTimeImmutable $from): self
    {
        return new self('reservations.close_too_early', ['when' => self::describe($from)]);
    }

    /** Build the exception for an absence marked before the tolerance after the start; it says from when it can be marked (CO-05). */
    public static function beforeTolerance(DateTimeImmutable $from): self
    {
        return new self('reservations.no_show_too_early', ['when' => self::describe($from)]);
    }

    /** Write the instant the way the message reads it, in the time zone it carries. */
    private static function describe(DateTimeImmutable $moment): string
    {
        return $moment->format('d/m/Y \a \l\a\s H:i');
    }
}
