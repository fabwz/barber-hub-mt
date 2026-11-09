<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use Src\Catalog\Barbershops\Application\Contracts\BarbershopSummaryReader;
use Src\Catalog\Barbershops\Application\DTOs\BarbershopSummary;

final class InMemoryBarbershopSummaryReader implements BarbershopSummaryReader
{
    /** @var list<string>|null */
    private ?array $asked = null;

    /** @param list<BarbershopSummary> $summaries */
    public function __construct(private readonly array $summaries) {}

    /**
     * Answer the stored summaries, remembering the identifiers that were asked for.
     *
     * @param  list<string>  $ids
     * @return list<BarbershopSummary>
     */
    public function findMany(array $ids): array
    {
        $this->asked = $ids;

        return $this->summaries;
    }

    /**
     * The identifiers of the last read, or nothing if the reader was never asked.
     *
     * @return list<string>|null
     */
    public function asked(): ?array
    {
        return $this->asked;
    }
}
