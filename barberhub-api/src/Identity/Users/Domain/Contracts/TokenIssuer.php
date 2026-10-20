<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Contracts;

use Src\Identity\Users\Domain\ValueObjects\UserId;

interface TokenIssuer
{
    /** Issue an access token for a device of the user and return it in plain text. */
    public function issue(UserId $userId, string $deviceName): string;
}
