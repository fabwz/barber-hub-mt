<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** A service of the catalog a voucher covers; the module only knows its identifier. */
final readonly class ServiceId extends Identifier {}
