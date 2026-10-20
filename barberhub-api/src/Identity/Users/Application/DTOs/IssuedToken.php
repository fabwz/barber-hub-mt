<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\DTOs;

final readonly class IssuedToken
{
    /** Carry the plain text token, shown only once. */
    public function __construct(public string $plainTextToken) {}
}
