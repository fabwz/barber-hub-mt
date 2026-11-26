<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Application\DTOs\NoticePreferences;
use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Domain\Exceptions\AccountNotFoundException;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class UpdateNoticePreferences
{
    /** Receive the user repository port. */
    public function __construct(private UserRepository $users) {}

    /**
     * Turn the reminders of the person's own account on or off (RF-47, NO-10). It only governs the reminders and takes effect from
     * the next reservation made or moved; the reminders already left are skipped when due, and turning them on again does not bring
     * back the reservations made while they were off.
     *
     * @throws AccountNotFoundException
     */
    public function execute(string $userId, bool $remindersEnabled): NoticePreferences
    {
        $id = new UserId($userId);

        if ($this->users->findById($id) === null) {
            throw AccountNotFoundException::create();
        }

        $this->users->setRemindersEnabled($id, $remindersEnabled);

        return new NoticePreferences($remindersEnabled);
    }
}
