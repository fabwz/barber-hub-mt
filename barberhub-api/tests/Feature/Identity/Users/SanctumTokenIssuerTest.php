<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Users;

use Laravel\Sanctum\PersonalAccessToken;
use Src\Identity\Users\Domain\ValueObjects\UserId;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Identity\Users\Infrastructure\Security\SanctumTokenIssuer;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class SanctumTokenIssuerTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    /** The token is stored as a hash and named after the device. */
    public function test_issued_token_is_stored_hashed(): void
    {
        $user = $this->account('ana@barberhub.test');

        $plainTextToken = new SanctumTokenIssuer()->issue(new UserId($user->id), 'Tablet del local');

        [$id, $secret] = explode('|', $plainTextToken, 2);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $id,
            'tokenable_id' => $user->id,
            'name' => 'Tablet del local',
            'token' => hash('sha256', $secret),
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $secret]);
        $this->assertNotNull(PersonalAccessToken::findToken($plainTextToken));
    }

    /** Each login creates its own token, so every device can be closed on its own. */
    public function test_every_device_gets_its_own_token(): void
    {
        $user = $this->account('ana@barberhub.test');
        $issuer = new SanctumTokenIssuer;

        $first = $issuer->issue(new UserId($user->id), 'Celular');
        $second = $issuer->issue(new UserId($user->id), 'Computadora');

        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    /** Create an account. */
    private function account(string $email): UserModel
    {
        return UserModel::query()->create(['name' => 'Ana Mora', 'email' => $email, 'password' => 'hash']);
    }
}
