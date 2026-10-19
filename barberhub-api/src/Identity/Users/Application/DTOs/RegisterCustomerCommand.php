<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\DTOs;

use DateTimeImmutable;
use SensitiveParameter;

final readonly class RegisterCustomerCommand
{
    /** Carry the data needed to register an account, with the instant of each acceptance (CL-02). */
    public function __construct(
        public string $name,
        public string $email,
        public string $phone,
        #[SensitiveParameter] public string $password,
        public DateTimeImmutable $termsAcceptedAt,
        public DateTimeImmutable $privacyAcceptedAt,
    ) {}
}
