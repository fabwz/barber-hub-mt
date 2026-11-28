<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Rent;

use DateTimeImmutable;
use Src\Catalog\Barbershops\Application\Contracts\RentInstallments;
use Src\Catalog\RentInstallments\Application\UseCases\EnsureRentInstallments;
use Src\Shared\Domain\ValueObjects\BarbershopId;

final readonly class RentModuleInstallments implements RentInstallments
{
    /** Receive the use case of the rent module that completes the installments of a barbershop. */
    public function __construct(private EnsureRentInstallments $ensure) {}

    /** A barbershop with no installments gets its first one. */
    public function openFor(BarbershopId $barbershopId, DateTimeImmutable $now): void
    {
        $this->ensure->execute($barbershopId->value, $now);
    }
}
