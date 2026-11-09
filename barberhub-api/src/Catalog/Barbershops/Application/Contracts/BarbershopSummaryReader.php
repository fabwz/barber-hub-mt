<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\Contracts;

use Src\Catalog\Barbershops\Application\DTOs\BarbershopSummary;

interface BarbershopSummaryReader
{
    /**
     * Read the barbershops with the given identifiers, ordered by name; an unknown identifier is skipped.
     *
     * @param  list<string>  $ids
     * @return list<BarbershopSummary>
     */
    public function findMany(array $ids): array;
}
