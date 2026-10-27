<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** The reservation a payment pays for; Payments only knows its identifier, never the reservation itself. */
final readonly class ReservationId extends Identifier {}
