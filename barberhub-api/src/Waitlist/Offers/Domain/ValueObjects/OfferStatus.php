<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\ValueObjects;

enum OfferStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
