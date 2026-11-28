<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Application\Contracts;

use DateTimeImmutable;

/**
 * The barbershops and the instant each one was registered, which fixes the day its rent falls due (PL-05). The table of barbershops has no
 * row security, so this reads every barbershop without a barbershop in context; that is the only reading across barbershops the module does.
 */
interface RegisteredBarbershops
{
    /**
     * The identifier of every barbershop.
     *
     * @return list<string>
     */
    public function ids(): array;

    /** The instant the barbershop was registered, or null when it does not exist. */
    public function registeredAt(string $barbershopId): ?DateTimeImmutable;
}
