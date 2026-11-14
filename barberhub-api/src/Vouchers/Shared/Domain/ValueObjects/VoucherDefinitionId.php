<?php

declare(strict_types=1);

namespace Src\Vouchers\Shared\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** The voucher a barbershop sells; a purchased voucher keeps it to know where it came from. */
final readonly class VoucherDefinitionId extends Identifier {}
