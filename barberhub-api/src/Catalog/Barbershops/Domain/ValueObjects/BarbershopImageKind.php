<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\ValueObjects;

/** The two images that identify a barbershop; the value is also the folder inside its space (RF-46, MT-08). */
enum BarbershopImageKind: string
{
    case Logo = 'logo';
    case Banner = 'banner';
}
