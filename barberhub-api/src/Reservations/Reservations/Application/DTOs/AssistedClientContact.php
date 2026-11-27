<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

/** Who an assisted client is and how to reach them: what the agenda of the owner shows. */
final readonly class AssistedClientContact
{
    public function __construct(
        public string $name,
        public string $phone,
    ) {}
}
