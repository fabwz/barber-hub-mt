<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Accounts;

use Src\Identity\Users\Application\UseCases\ListAccountSummaries;
use Src\Payments\Payments\Application\Contracts\PayerAccounts;
use Src\Payments\Payments\Application\DTOs\PayerAccount;

final readonly class UsersPayerAccounts implements PayerAccounts
{
    /** Receive the Users use case that describes accounts. */
    public function __construct(private ListAccountSummaries $listAccountSummaries) {}

    /** Read the account through the Users module and keep the name and the email. */
    public function find(string $userId): ?PayerAccount
    {
        $summary = $this->listAccountSummaries->execute([$userId])[0] ?? null;

        return $summary === null ? null : new PayerAccount($summary->name, $summary->email);
    }
}
