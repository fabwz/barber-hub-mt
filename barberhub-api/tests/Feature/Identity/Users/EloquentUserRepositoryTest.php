<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Users;

use DateTimeImmutable;
use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\Exceptions\EmailAlreadyRegisteredException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\Phone;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Identity\Users\Infrastructure\Persistence\Repositories\EloquentUserRepository;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class EloquentUserRepositoryTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private EloquentUserRepository $repository;

    /** Create the repository every scenario works with. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentUserRepository;
    }

    /** A saved account is read back unchanged by its email, with the date of each acceptance. */
    public function test_saved_account_is_read_back_unchanged(): void
    {
        $user = $this->account('ana@barberhub.test');

        $this->repository->save($user);

        $found = $this->repository->findByEmail(new Email('ana@barberhub.test'));

        $this->assertEquals($user, $found);
        $this->assertEquals(new DateTimeImmutable('2026-10-19 10:00:00-06:00'), $found?->termsAcceptedAt);
        $this->assertEquals(new DateTimeImmutable('2026-10-19 10:00:05-06:00'), $found?->privacyAcceptedAt);
    }

    /** The lookup ignores the spelling the person types. */
    public function test_lookup_ignores_case_and_spaces(): void
    {
        $this->repository->save($this->account('ana@barberhub.test'));

        $this->assertNotNull($this->repository->findByEmail(new Email('  Ana@BarberHub.test ')));
    }

    /** An unknown email finds nothing. */
    public function test_unknown_email_finds_nothing(): void
    {
        $this->assertNull($this->repository->findByEmail(new Email('nadie@barberhub.test')));
    }

    /** The stored password is the hash the entity carries and stays hidden from serialization. */
    public function test_password_is_stored_as_given_and_hidden(): void
    {
        $user = $this->account('ana@barberhub.test');

        $this->repository->save($user);

        $model = UserModel::query()->sole();
        $this->assertSame($user->passwordHash, $model->password);
        $this->assertArrayNotHasKey('password', $model->toArray());
    }

    /** The identities are version 7 UUIDs. */
    public function test_next_id_is_a_uuid_v7(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $this->repository->nextId()->value,
        );
    }

    /** The unique key surfaces as the domain error and keeps the transaction usable. */
    public function test_repeated_email_raises_the_domain_error(): void
    {
        $this->repository->save($this->account('ana@barberhub.test'));

        try {
            $this->repository->save($this->account('ana@barberhub.test'));
            $this->fail('A second account with the same email was accepted.');
        } catch (EmailAlreadyRegisteredException) {
            $this->assertDatabaseCount('users', 1);
        }
    }

    /** Build an account with a fresh identity. */
    private function account(string $email): User
    {
        return User::create(
            $this->repository->nextId(),
            new UserName('Ana Mora'),
            new Email($email),
            new Phone('8888-0000'),
            password_hash('Secreto#123', PASSWORD_BCRYPT, ['cost' => 4]),
            new DateTimeImmutable('2026-10-19 10:00:00-06:00'),
            new DateTimeImmutable('2026-10-19 10:00:05-06:00'),
        );
    }
}
