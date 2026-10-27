<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\Entities;

use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentPolicy;
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
        public PaymentPolicy $paymentPolicy,
    ) {}

    /**
     * Register a barbershop with its name, city, contact phone and the public link it keeps for good (RF-01, RF-02). It
     * starts charging nothing (PA-01).
     */
    public static function create(BarbershopId $id, BarbershopName $name, City $city, Phone $phone, PublicLink $link): self
    {
        return new self($id, $name, $city, $phone, $link, PaymentPolicy::initial());
    }

    /** Rebuild a barbershop from its stored state. */
    public static function reconstitute(BarbershopId $id, BarbershopName $name, City $city, Phone $phone, PublicLink $link, PaymentPolicy $paymentPolicy): self
    {
        return new self($id, $name, $city, $phone, $link, $paymentPolicy);
    }

    /** The same barbershop with another payment policy, which applies to the reservations and charges made from now on (RF-08). */
    public function withPaymentPolicy(PaymentPolicy $paymentPolicy): self
    {
        return new self($this->id, $this->name, $this->city, $this->phone, $this->link, $paymentPolicy);
    }
}
