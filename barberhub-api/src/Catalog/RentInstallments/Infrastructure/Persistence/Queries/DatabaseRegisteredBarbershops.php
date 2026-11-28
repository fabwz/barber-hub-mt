<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Infrastructure\Persistence\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Src\Catalog\RentInstallments\Application\Contracts\RegisteredBarbershops;

/** Reads `barbershops`, which has no row security (docs/database.md), so it needs no barbershop in context and no extra database role. */
final readonly class DatabaseRegisteredBarbershops implements RegisteredBarbershops
{
    /** Every barbershop, oldest first. */
    public function ids(): array
    {
        return array_values(DB::table('barbershops')->orderBy('created_at')->orderBy('id')->pluck('id')->map(fn (mixed $id): string => (string) $id)->all());
    }

    /** The registration instant of the barbershop (`created_at`, a timestamp with time zone). */
    public function registeredAt(string $barbershopId): ?DateTimeImmutable
    {
        $createdAt = DB::table('barbershops')->where('id', $barbershopId)->value('created_at');

        return $createdAt === null ? null : new DateTimeImmutable((string) $createdAt);
    }
}
