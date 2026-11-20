<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** The row of the line an offer is made to. */
final readonly class WaitlistEntryId extends Identifier {}
