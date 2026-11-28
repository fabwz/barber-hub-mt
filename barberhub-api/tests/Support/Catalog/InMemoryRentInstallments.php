<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use DateTimeImmutable;
use Src\Catalog\Barbershops\Application\Contracts\RentInstallments;
use Src\Shared\Domain\ValueObjects\BarbershopId;

final class InMemoryRentInstallments implements RentInstallments
{
    /** @var list<array{barbershopId: string, now: DateTimeImmutable}> */
    private array $opened = [];

    /** Record that the first installment was opened. */
    public function openFor(BarbershopId $barbershopId, DateTimeImmutable $now): void
    {
        $this->opened[] = ['barbershopId' => $barbershopId->value, 'now' => $now];
    }

    /** @return list<array{barbershopId: string, now: DateTimeImmutable}> */
    public function opened(): array
    {
        return $this->opened;
    }
}
