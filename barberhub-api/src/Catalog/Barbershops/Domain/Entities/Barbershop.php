<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\Entities;

use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
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
    ) {}

    /** Register a barbershop with its name, city and contact phone (RF-01). */
    public static function create(BarbershopId $id, BarbershopName $name, City $city, Phone $phone): self
    {
        return new self($id, $name, $city, $phone);
    }

    /** Rebuild a barbershop from its stored state. */
    public static function reconstitute(BarbershopId $id, BarbershopName $name, City $city, Phone $phone): self
    {
        return new self($id, $name, $city, $phone);
    }
}
