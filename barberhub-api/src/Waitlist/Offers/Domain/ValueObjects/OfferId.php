<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** The identity of an offer. */
final readonly class OfferId extends Identifier {}
