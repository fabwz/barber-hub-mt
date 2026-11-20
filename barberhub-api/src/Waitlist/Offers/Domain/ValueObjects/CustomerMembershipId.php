<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** The customer role of the person who waits. */
final readonly class CustomerMembershipId extends Identifier {}
