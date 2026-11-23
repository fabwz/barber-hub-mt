<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\Entities;

use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopFeatures;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopImageKind;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopImages;
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
        public BarbershopFeatures $features,
        public BarbershopImages $images,
    ) {}

    /**
     * Register a barbershop with its name, city, contact phone and the public link it keeps for good (RF-01, RF-02). It
     * starts charging nothing (PA-01), with no address, contact email or social links, and with the vouchers and the waitlist off (RF-09).
     */
    public static function create(BarbershopId $id, BarbershopName $name, City $city, Phone $phone, PublicLink $link): self
    {
        return new self($id, $name, $city, $phone, $link, PaymentPolicy::initial(), PublicProfile::empty(), BarbershopFeatures::initial(), BarbershopImages::none());
    }

    /** Rebuild a barbershop from its stored state; one stored without a profile has none, one without features has them off and one without images has none. */
    public static function reconstitute(BarbershopId $id, BarbershopName $name, City $city, Phone $phone, PublicLink $link, PaymentPolicy $paymentPolicy, ?PublicProfile $profile = null, ?BarbershopFeatures $features = null, ?BarbershopImages $images = null): self
    {
        return new self($id, $name, $city, $phone, $link, $paymentPolicy, $profile ?? PublicProfile::empty(), $features ?? BarbershopFeatures::initial(), $images ?? BarbershopImages::none());
    }

    /** The same barbershop with another payment policy, which applies to the reservations and charges made from now on (RF-08). */
    public function withPaymentPolicy(PaymentPolicy $paymentPolicy): self
    {
        return new self($this->id, $this->name, $this->city, $this->phone, $this->link, $paymentPolicy, $this->profile, $this->features, $this->images);
    }

    /** The same barbershop with another name, city and phone. The public link stays as it is, whatever the name (MT-07). */
    public function withDetails(BarbershopName $name, City $city, Phone $phone): self
    {
        return new self($this->id, $name, $city, $phone, $this->link, $this->paymentPolicy, $this->profile, $this->features, $this->images);
    }

    /** The same barbershop with another public profile: address, contact email and social links. */
    public function withProfile(PublicProfile $profile): self
    {
        return new self($this->id, $this->name, $this->city, $this->phone, $this->link, $this->paymentPolicy, $profile, $this->features, $this->images);
    }

    /** The same barbershop with the vouchers and the waitlist switched on or off; what is already bought or offered is not touched (RF-09). */
    public function withFeatures(BarbershopFeatures $features): self
    {
        return new self($this->id, $this->name, $this->city, $this->phone, $this->link, $this->paymentPolicy, $this->profile, $features, $this->images);
    }

    /** The same barbershop with another logo or banner (RF-46); the path is the one the image storage gave to the new file. */
    public function withImage(BarbershopImageKind $kind, string $path): self
    {
        return new self($this->id, $this->name, $this->city, $this->phone, $this->link, $this->paymentPolicy, $this->profile, $this->features, $this->images->replacing($kind, $path));
    }
}
