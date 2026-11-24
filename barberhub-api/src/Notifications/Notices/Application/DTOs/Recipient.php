<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\DTOs;

/** The person a notice is for and the address it reaches them at. */
final readonly class Recipient
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
