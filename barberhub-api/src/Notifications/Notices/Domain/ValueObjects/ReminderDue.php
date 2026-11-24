<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Domain\ValueObjects;

use DateTimeImmutable;

/** A reminder that still fits before a reservation: its kind and the instant it is due. */
final readonly class ReminderDue
{
    public function __construct(
        public ReminderKind $kind,
        public DateTimeImmutable $at,
    ) {}
}
