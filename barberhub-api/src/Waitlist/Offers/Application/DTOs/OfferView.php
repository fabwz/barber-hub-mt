<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\DTOs;

use Src\Waitlist\Offers\Domain\Entities\Offer;

final readonly class OfferView
{
    /** Carry an offer with what it does not know itself: the service its row asks for and the name of the collaborator whose space it holds. */
    public function __construct(
        public Offer $offer,
        public string $serviceId,
        public ?string $collaboratorName,
    ) {}
}
