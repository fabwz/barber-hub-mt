<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Persistence\Queries;

use Src\Catalog\Barbershops\Application\Contracts\BarbershopSummaryReader;
use Src\Catalog\Barbershops\Application\DTOs\BarbershopSummary;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Models\BarbershopModel;

final readonly class EloquentBarbershopSummaryReader implements BarbershopSummaryReader
{
    /**
     * Read the barbershops with the given identifiers in one query, ordered by name and then by identifier.
     *
     * @param  list<string>  $ids
     * @return list<BarbershopSummary>
     */
    public function findMany(array $ids): array
    {
        $barbershops = BarbershopModel::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'public_link'])
            ->map(fn (BarbershopModel $barbershop): BarbershopSummary => new BarbershopSummary($barbershop->id, $barbershop->name, $barbershop->public_link))
            ->all();

        return array_values($barbershops);
    }
}
