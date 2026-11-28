<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Infrastructure\Persistence\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Src\Catalog\RentInstallments\Application\Contracts\BarbershopBlockState;

/** Reads and writes `barbershops.blocked_at`. The table has no row security (docs/database.md), so it needs no barbershop in context. */
final readonly class DatabaseBarbershopBlockState implements BarbershopBlockState
{
    /** Instants travel as text with their offset, so they never depend on the time zone of the process. */
    private const string INSTANT_FORMAT = 'Y-m-d H:i:s.uP';

    /** `SELECT ... FOR UPDATE` on the row; it waits for any other transaction that holds it. */
    public function lock(string $barbershopId): ?DateTimeImmutable
    {
        $blockedAt = DB::table('barbershops')->where('id', $barbershopId)->lockForUpdate()->value('blocked_at');

        return $blockedAt === null ? null : new DateTimeImmutable((string) $blockedAt);
    }

    /** A single conditional UPDATE: only a barbershop that is not blocked changes. */
    public function block(string $barbershopId, DateTimeImmutable $at): bool
    {
        return DB::table('barbershops')->where('id', $barbershopId)->whereNull('blocked_at')->update(['blocked_at' => $at->format(self::INSTANT_FORMAT)]) === 1;
    }

    /** A single conditional UPDATE: only a blocked barbershop changes. */
    public function unblock(string $barbershopId): bool
    {
        return DB::table('barbershops')->where('id', $barbershopId)->whereNotNull('blocked_at')->update(['blocked_at' => null]) === 1;
    }
}
