<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Domain\Contracts;

use Src\Shared\Domain\ValueObjects\Money;

/**
 * The monthly rent in force today, the same for every barbershop (PL-04, PD-09). An installment copies it when it is created, so a later
 * change never touches an installment already generated. Today it comes from the environment; it can come from a value the operator edits
 * without changing how installments are generated.
 */
interface RentAmount
{
    /** The amount a new installment is created with. */
    public function current(): Money;
}
