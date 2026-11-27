<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** The owner role that registered an assisted reservation (RF-12). */
final readonly class CreatorMembershipId extends Identifier {}
