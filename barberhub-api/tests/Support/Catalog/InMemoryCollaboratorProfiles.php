<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use Src\Catalog\Barbershops\Application\Contracts\CollaboratorProfiles;

final class InMemoryCollaboratorProfiles implements CollaboratorProfiles
{
    /** @var list<array{string, string}> */
    public array $asked = [];

    /** @param array<string, string> $byBarbershop */
    public function __construct(private readonly array $byBarbershop) {}

    /** Answer the stored profile of the barbershop, remembering each lookup. */
    public function of(string $userId, string $barbershopId): ?string
    {
        $this->asked[] = [$userId, $barbershopId];

        return $this->byBarbershop[$barbershopId] ?? null;
    }
}
