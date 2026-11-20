<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\DTOs;

use Src\Waitlist\Offers\Domain\Entities\Offer;

final readonly class OfferView
{
    /** Carry an offer with the service its row asks for, which the offer itself does not know. */
    public function __construct(
        public Offer $offer,
        public string $serviceId,
    ) {}
}
