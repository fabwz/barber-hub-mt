<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Src\Shared\Domain\Contracts\DatabaseHealth;
use Throwable;

final class PostgresDatabaseHealth implements DatabaseHealth
{
    public function isAvailable(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable $e) {
            // Only the class: the message may carry the host or credentials.
            Log::warning('Health check: the database is unavailable.', ['exception' => $e::class]);

            return false;
        }
    }
}
