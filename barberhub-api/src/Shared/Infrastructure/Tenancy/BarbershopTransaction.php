<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;

final readonly class BarbershopTransaction
{
    public const string SETTING = 'app.barbershop_id';

    /**
     * Run the operation in a database transaction that only reaches the rows of one barbershop; null reaches none.
     * Inside another transaction this one is a savepoint, and the barbershop lasts until the outermost one ends.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(?string $barbershopId, Closure $operation): mixed
    {
        return DB::transaction(function () use ($barbershopId, $operation): mixed {
            $this->scope($barbershopId);

            return $operation();
        });
    }

    /** Set the barbershop of the open transaction; the value disappears when the transaction ends. */
    public function scope(?string $barbershopId): void
    {
        DB::statement('select set_config(?, ?, true)', [self::SETTING, $barbershopId ?? '']);
    }
}
