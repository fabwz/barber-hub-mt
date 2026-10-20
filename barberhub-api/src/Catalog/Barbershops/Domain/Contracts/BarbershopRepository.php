<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\Contracts;

use Src\Catalog\Barbershops\Domain\Entities\Barbershop;
use Src\Catalog\Barbershops\Domain\Exceptions\PublicLinkTakenException;
use Src\Shared\Domain\ValueObjects\BarbershopId;

interface BarbershopRepository
{
    /** Generate the identity for a new barbershop. */
    public function nextId(): BarbershopId;

    /**
     * Persist a new or modified barbershop; the public link is stored only when the barbershop is new.
     *
     * @throws PublicLinkTakenException When another barbershop already has the link of a new one.
     */
    public function save(Barbershop $barbershop): void;

    /** Find a barbershop by its identity. */
    public function find(BarbershopId $id): ?Barbershop;
}
