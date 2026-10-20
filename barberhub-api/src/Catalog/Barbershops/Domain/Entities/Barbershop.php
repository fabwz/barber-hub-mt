<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\Entities;

use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
use Src\Catalog\Barbershops\Domain\ValueObjects\PublicLink;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Phone;

final readonly class Barbershop
{
    /** Hold the full state of a barbershop. */
    private function __construct(
        public BarbershopId $id,
        public BarbershopName $name,
        public City $city,
        public Phone $phone,
        public PublicLink $link,
    ) {}

    /** Register a barbershop with its name, city, contact phone and the public link it keeps for good (RF-01, RF-02). */
    public static function create(BarbershopId $id, BarbershopName $name, City $city, Phone $phone, PublicLink $link): self
    {
        return new self($id, $name, $city, $phone, $link);
    }

    /** Rebuild a barbershop from its stored state. */
    public static function reconstitute(BarbershopId $id, BarbershopName $name, City $city, Phone $phone, PublicLink $link): self
    {
        return new self($id, $name, $city, $phone, $link);
    }
}
