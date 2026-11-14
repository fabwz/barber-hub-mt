<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

use Src\Shared\Domain\ValueObjects\Money;

/** A voucher a customer is buying, as Payments needs it: the identifier and the price it kept, which is what is charged. */
final readonly class PayableVoucher
{
    public function __construct(public string $id, public Money $price) {}
}
