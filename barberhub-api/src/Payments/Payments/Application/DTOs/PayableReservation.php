<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

use Src\Shared\Domain\ValueObjects\Money;

/** A reservation as Payments needs it: the identifier, the price it kept and whether it is temporary with its payment window open. */
final readonly class PayableReservation
{
    public function __construct(public string $id, public Money $price, public bool $open) {}
}
