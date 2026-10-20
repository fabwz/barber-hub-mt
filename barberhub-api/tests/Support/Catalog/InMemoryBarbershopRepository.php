<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use Src\Catalog\Barbershops\Domain\Contracts\BarbershopRepository;
use Src\Catalog\Barbershops\Domain\Entities\Barbershop;
use Src\Catalog\Barbershops\Domain\Exceptions\PublicLinkTakenException;
use Src\Shared\Domain\ValueObjects\BarbershopId;

final class InMemoryBarbershopRepository implements BarbershopRepository
{
    /** @var array<string, Barbershop> */
    private array $barbershops = [];

    private int $sequence = 0;

    private int $saveAttempts = 0;

    /** Generate a predictable identity. */
    public function nextId(): BarbershopId
    {
        return new BarbershopId(sprintf('0192f0c4-0000-7000-8000-%012d', ++$this->sequence));
    }

    /** Store the barbershop, refusing one whose link another barbershop already has. */
    public function save(Barbershop $barbershop): void
    {
        $this->saveAttempts++;

        foreach ($this->barbershops as $stored) {
            if ($stored->id->value !== $barbershop->id->value && $stored->link->value === $barbershop->link->value) {
                throw PublicLinkTakenException::create();
            }
        }

        $this->barbershops[$barbershop->id->value] = $barbershop;
    }

    /** Find a barbershop by its identity. */
    public function find(BarbershopId $id): ?Barbershop
    {
        return $this->barbershops[$id->value] ?? null;
    }

    /**
     * List the stored barbershops.
     *
     * @return list<Barbershop>
     */
    public function all(): array
    {
        return array_values($this->barbershops);
    }

    /** Count the times a save was attempted, refused ones included. */
    public function saveAttempts(): int
    {
        return $this->saveAttempts;
    }
}
