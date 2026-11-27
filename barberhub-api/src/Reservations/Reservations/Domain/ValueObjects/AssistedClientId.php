<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** The assisted client a reservation belongs to when the owner registered it for a person without an account (RF-12). */
final readonly class AssistedClientId extends Identifier {}
