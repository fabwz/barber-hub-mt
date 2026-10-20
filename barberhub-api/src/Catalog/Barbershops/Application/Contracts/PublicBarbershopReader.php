<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\Contracts;

use Src\Catalog\Barbershops\Application\DTOs\PublicBarbershop;
use Src\Catalog\Barbershops\Domain\ValueObjects\PublicLink;

interface PublicBarbershopReader
{
    /** Read the public data of the barbershop that owns a link. */
    public function findByLink(PublicLink $link): ?PublicBarbershop;
}
