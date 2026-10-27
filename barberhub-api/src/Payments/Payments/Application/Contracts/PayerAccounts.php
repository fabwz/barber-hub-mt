<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Contracts;

use Src\Payments\Payments\Application\DTOs\PayerAccount;

interface PayerAccounts
{
    /** The name and email of the account of the person who pays, or null when it does not exist. */
    public function find(string $userId): ?PayerAccount;
}
