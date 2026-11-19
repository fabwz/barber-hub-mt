<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** A row of the waitlist. */
final readonly class WaitlistEntryId extends Identifier {}
