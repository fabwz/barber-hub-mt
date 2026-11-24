<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Shared\Domain\ValueObjects\UserId;

/** Whether an account wants the reminders of its reservations by email (RF-47, NO-10). */
final readonly class FindReminderPreference
{
    public function __construct(private UserRepository $users) {}

    /** True until the account turns them off. */
    public function execute(string $userId): bool
    {
        return $this->users->remindersEnabled(new UserId($userId));
    }
}
