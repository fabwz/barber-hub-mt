<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** The reservation that answers an offer: the one booked when the customer accepted it. */
final readonly class ReservationId extends Identifier {}
