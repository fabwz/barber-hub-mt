<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\Contracts;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\BarbershopId;

/** The rent of a barbershop, kept by the `RentInstallments` module: registering a barbershop creates its first installment (RF-01, RF-57). */
interface RentInstallments
{
    /** Create the first installment of a barbershop registered at this instant, in the transaction that registers it. */
    public function openFor(BarbershopId $barbershopId, DateTimeImmutable $now): void;
}
