<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Domain\ValueObjects;

/** Whether the rent of a month was paid (PL-05). Paying belongs to RF-59; this issue only creates pending installments. */
enum InstallmentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
}
