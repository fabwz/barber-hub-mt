<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use Src\Catalog\Barbershops\Application\Contracts\PublicBarbershopReader;
use Src\Catalog\Barbershops\Application\DTOs\PublicBarbershop;
use Src\Catalog\Barbershops\Domain\ValueObjects\PublicLink;

final class InMemoryPublicBarbershopReader implements PublicBarbershopReader
{
    /** @var array<string, PublicBarbershop> */
    private array $barbershops = [];

    private int $reads = 0;

    /** Make a barbershop reachable by a link. */
    public function add(string $link, PublicBarbershop $barbershop): void
    {
        $this->barbershops[$link] = $barbershop;
    }

    /** Read the barbershop of a link. */
    public function findByLink(PublicLink $link): ?PublicBarbershop
    {
        $this->reads++;

        return $this->barbershops[$link->value] ?? null;
    }

    /** Count the times a link was looked up. */
    public function reads(): int
    {
        return $this->reads;
    }
}
