<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** The reservation a movement of the balance belongs to; the module only knows its identifier. */
final readonly class ReservationId extends Identifier {}
