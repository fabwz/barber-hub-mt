<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Application\DTOs\NoticePreferences;
use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Domain\Exceptions\AccountNotFoundException;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class ShowNoticePreferences
{
    /** Receive the user repository port. */
    public function __construct(private UserRepository $users) {}

    /**
     * Read the notice preferences of the person who asks (RF-47); the reminders are on until the person turns them off.
     *
     * @throws AccountNotFoundException
     */
    public function execute(string $userId): NoticePreferences
    {
        $id = new UserId($userId);

        if ($this->users->findById($id) === null) {
            throw AccountNotFoundException::create();
        }

        return new NoticePreferences($this->users->remindersEnabled($id));
    }
}
