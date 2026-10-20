<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Users;

use Laravel\Sanctum\PersonalAccessToken;
use Src\Identity\Users\Domain\ValueObjects\UserId;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Identity\Users\Infrastructure\Security\SanctumTokenRevoker;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class SanctumTokenRevokerTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private SanctumTokenRevoker $revoker;

    private UserModel $user;

    /** Create the revoker and a user with tokens on two devices. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->revoker = new SanctumTokenRevoker;
        $this->user = $this->account('ana@barberhub.test');
        $this->user->createToken('Celular');
        $this->user->createToken('Computadora');
    }

    /** Revoking one token keeps the other devices open. */
    public function test_revokes_one_token_of_the_user(): void
    {
        $phone = $this->tokenOf($this->user, 'Celular');

        $this->revoker->revoke(new UserId($this->user->id), (string) $phone->id);

        $this->assertSame(['Computadora'], $this->user->tokens()->pluck('name')->all());
    }

    /** A token of another person cannot be revoked with the identity of the user. */
    public function test_ignores_a_token_of_another_person(): void
    {
        $other = $this->account('luis@barberhub.test');
        $other->createToken('Celular');

        $this->revoker->revoke(new UserId($this->user->id), (string) $this->tokenOf($other, 'Celular')->id);

        $this->assertDatabaseCount('personal_access_tokens', 3);
    }

    /** Revoking every token closes all the devices of the user and only of the user. */
    public function test_revokes_every_token_of_the_user(): void
    {
        $other = $this->account('luis@barberhub.test');
        $other->createToken('Celular');

        $this->revoker->revokeAll(new UserId($this->user->id));

        $this->assertSame(0, $this->user->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
    }

    /** Create an account. */
    private function account(string $email): UserModel
    {
        return UserModel::query()->create(['name' => 'Ana Mora', 'email' => $email, 'password' => 'hash']);
    }

    /** Find the token of a user by the name of its device. */
    private function tokenOf(UserModel $user, string $device): PersonalAccessToken
    {
        return $user->tokens()->where('name', $device)->sole();
    }
}
