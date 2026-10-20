<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Users;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class RevokeTokensTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string ENDPOINT = '/api/v1/tokens';

    private const string PASSWORD = 'Clave#segura1';

    /** Create the account whose sessions every scenario opens and closes. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->account('ana@barberhub.test');
    }

    /** Closing the session revokes only the token in use; the other device stays open. */
    public function test_closing_the_session_revokes_only_the_token_in_use(): void
    {
        $phone = $this->login('ana@barberhub.test', 'Celular');
        $laptop = $this->login('ana@barberhub.test', 'Computadora');

        $this->deleteWithToken('/actual', $phone)->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'Celular']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Computadora']);
        $this->deleteWithToken('/actual', $laptop)->assertNoContent();
    }

    /** Closing every session revokes all the tokens of the person and none of anyone else. */
    public function test_closing_every_session_revokes_all_the_tokens_of_the_person(): void
    {
        $this->account('luis@barberhub.test');
        $phone = $this->login('ana@barberhub.test', 'Celular');
        $laptop = $this->login('ana@barberhub.test', 'Computadora');
        $other = $this->login('luis@barberhub.test', 'Tablet del local');

        $this->deleteWithToken('', $laptop)->assertNoContent();

        $this->assertSame(['Tablet del local'], $this->tokenNames());
        $this->deleteWithToken('/actual', $phone)->assertUnauthorized();
        $this->deleteWithToken('/actual', $other)->assertNoContent();
    }

    /** A revoked token answers the unauthenticated problem, like a missing one. */
    public function test_revoked_token_answers_unauthenticated(): void
    {
        $token = $this->login('ana@barberhub.test', 'Celular');
        $this->deleteWithToken('/actual', $token)->assertNoContent();

        foreach (['/actual', ''] as $path) {
            $this->deleteWithToken($path, $token)
                ->assertUnauthorized()
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', 'http://localhost/problemas/no-autenticado');
        }
    }

    /** Both operations require a token. */
    public function test_closing_a_session_requires_a_token(): void
    {
        foreach (['/actual', ''] as $path) {
            $this->deleteJson(self::ENDPOINT.$path)
                ->assertUnauthorized()
                ->assertJsonPath('type', 'http://localhost/problemas/no-autenticado');
        }
    }

    /** Create an active account whose password is the one of the tests. */
    private function account(string $email): void
    {
        UserModel::query()->create([
            'name' => 'Ana Mora',
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    /** Log in through the API and return the plain text token. */
    private function login(string $email, string $device): string
    {
        return $this->postJson(self::ENDPOINT, ['correo' => $email, 'contrasena' => self::PASSWORD, 'dispositivo' => $device])
            ->assertCreated()
            ->json('data.token');
    }

    /**
     * Send a request with a token, forgetting the user the guards kept from the previous request.
     *
     * @return TestResponse<Response>
     */
    private function deleteWithToken(string $path, string $token): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->deleteJson(self::ENDPOINT.$path);
    }

    /**
     * Device names of the tokens still stored.
     *
     * @return list<mixed>
     */
    private function tokenNames(): array
    {
        return array_values(DB::table('personal_access_tokens')->orderBy('name')->pluck('name')->all());
    }
}
