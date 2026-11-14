<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** The role of the customer in the barbershop, the owner of the voucher; the module only knows its identifier. */
final readonly class CustomerMembershipId extends Identifier {}
