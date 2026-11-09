<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\Entities;

use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentPolicy;
use Src\Catalog\Barbershops\Domain\ValueObjects\PublicLink;
use Src\Catalog\Barbershops\Domain\ValueObjects\PublicProfile;
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
        public PublicProfile $profile,
    ) {}

    /**
     * Register a barbershop with its name, city, contact phone and the public link it keeps for good (RF-01, RF-02). It
     * starts charging nothing (PA-01) and with no address, contact email or social links.
     */
    public static function create(BarbershopId $id, BarbershopName $name, City $city, Phone $phone, PublicLink $link): self
    {
        return new self($id, $name, $city, $phone, $link, PaymentPolicy::initial(), PublicProfile::empty());
    }

    /** Rebuild a barbershop from its stored state; one stored without a profile has none. */
    public static function reconstitute(BarbershopId $id, BarbershopName $name, City $city, Phone $phone, PublicLink $link, PaymentPolicy $paymentPolicy, ?PublicProfile $profile = null): self
    {
        return new self($id, $name, $city, $phone, $link, $paymentPolicy, $profile ?? PublicProfile::empty());
    }

    /** The same barbershop with another payment policy, which applies to the reservations and charges made from now on (RF-08). */
    public function withPaymentPolicy(PaymentPolicy $paymentPolicy): self
    {
        return new self($this->id, $this->name, $this->city, $this->phone, $this->link, $paymentPolicy, $this->profile);
    }

    /** The same barbershop with another name, city and phone. The public link stays as it is, whatever the name (MT-07). */
    public function withDetails(BarbershopName $name, City $city, Phone $phone): self
    {
        return new self($this->id, $name, $city, $phone, $this->link, $this->paymentPolicy, $this->profile);
    }

    /** The same barbershop with another public profile: address, contact email and social links. */
    public function withProfile(PublicProfile $profile): self
    {
        return new self($this->id, $this->name, $this->city, $this->phone, $this->link, $this->paymentPolicy, $profile);
    }
}
