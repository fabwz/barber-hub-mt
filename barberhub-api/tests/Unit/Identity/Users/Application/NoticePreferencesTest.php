<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Users\Application;

use PHPUnit\Framework\TestCase;
use Src\Identity\Users\Application\UseCases\ShowNoticePreferences;
use Src\Identity\Users\Application\UseCases\UpdateNoticePreferences;
use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\Exceptions\AccountNotFoundException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Tests\Support\Identity\InMemoryUserRepository;

/** Reading and changing whether an account wants the reminders by email, without a framework (RF-47, NO-10). */
final class NoticePreferencesTest extends TestCase
{
    private InMemoryUserRepository $users;

    private User $ana;

    /** Start with one account that has not chosen anything. */
    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository;
        $this->ana = User::createStaff($this->users->nextId(), new UserName('Ana Mora'), new Email('ana@barberhub.test'), 'hash');
        $this->users->save($this->ana);
    }

    /** The reminders are on until the account says otherwise. */
    public function test_the_reminders_are_on_by_default(): void
    {
        $this->assertTrue((new ShowNoticePreferences($this->users))->execute($this->ana->id->value)->remindersEnabled);
    }

    /** Turning them off and on is stored and answered. */
    public function test_turns_the_reminders_off_and_on(): void
    {
        $update = new UpdateNoticePreferences($this->users);

        $this->assertFalse($update->execute($this->ana->id->value, false)->remindersEnabled);
        $this->assertFalse($this->users->remindersEnabled($this->ana->id));
        $this->assertFalse((new ShowNoticePreferences($this->users))->execute($this->ana->id->value)->remindersEnabled);

        $this->assertTrue($update->execute($this->ana->id->value, true)->remindersEnabled);
        $this->assertTrue($this->users->remindersEnabled($this->ana->id));
    }

    /** Turning them off twice leaves them off, and the account itself is not touched. */
    public function test_is_idempotent_and_leaves_the_account_alone(): void
    {
        $update = new UpdateNoticePreferences($this->users);

        $update->execute($this->ana->id->value, false);
        $update->execute($this->ana->id->value, false);

        $this->assertFalse($this->users->remindersEnabled($this->ana->id));
        $this->assertEquals($this->ana, $this->users->findById($this->ana->id));
    }

    /** An account that does not exist is not found, on reading and on writing. */
    public function test_an_unknown_account_is_not_found(): void
    {
        $unknown = '0192f0c4-0000-7000-8000-999999999999';

        $this->expectException(AccountNotFoundException::class);
        (new ShowNoticePreferences($this->users))->execute($unknown);
    }

    /** Writing for an account that does not exist is not found either, and nothing is stored. */
    public function test_an_unknown_account_cannot_be_changed(): void
    {
        $this->expectException(AccountNotFoundException::class);
        (new UpdateNoticePreferences($this->users))->execute('0192f0c4-0000-7000-8000-999999999999', false);
    }
}
