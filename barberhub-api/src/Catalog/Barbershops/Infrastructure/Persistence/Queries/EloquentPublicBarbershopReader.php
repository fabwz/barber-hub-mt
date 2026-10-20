<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Persistence\Queries;

use Src\Catalog\Barbershops\Application\Contracts\PublicBarbershopReader;
use Src\Catalog\Barbershops\Application\DTOs\PublicBarbershop;
use Src\Catalog\Barbershops\Domain\ValueObjects\PublicLink;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Models\BarbershopModel;

final readonly class EloquentPublicBarbershopReader implements PublicBarbershopReader
{
    /** Read the barbershop whose link matches, with one query. */
    public function findByLink(PublicLink $link): ?PublicBarbershop
    {
        $barbershop = BarbershopModel::query()->where('public_link', $link->value)->first();

        return $barbershop === null ? null : new PublicBarbershop(
            $barbershop->id,
            $barbershop->name,
            $barbershop->city,
            $barbershop->phone,
        );
    }
}
