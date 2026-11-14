<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** The purchased voucher a payment pays for; Payments only knows its identifier, never the voucher itself. */
final readonly class PurchasedVoucherId extends Identifier {}
