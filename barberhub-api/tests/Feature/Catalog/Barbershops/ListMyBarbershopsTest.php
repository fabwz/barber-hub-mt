<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Barbershops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/** GET /api/v1/mis-barberias: the barbershops where the authenticated person holds an active role (RF-43, UP-07, UP-08). */
final class ListMyBarbershopsTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string ENDPOINT = '/api/v1/mis-barberias';

    private UserModel $ana;

    /** Create the person every scenario lists the barbershops of. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ana = $this->createUser('ana@barberhub.test');
    }

    /** A person with a role in one barbershop sees only that one, with the id, name, link and role. */
    public function test_person_with_a_role_in_one_barbershop_sees_it(): void
    {
        $puraNavaja = $this->createBarbershop('Barbería Pura Navaja', 'pura-navaja');
        $this->createBarbershop('Barbería Fade Lab', 'fade-lab');
        $this->grant($this->ana, $puraNavaja, 'owner');

        $this->getJson(self::ENDPOINT, $this->bearer($this->ana))
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $puraNavaja, 'nombre' => 'Barbería Pura Navaja', 'enlace' => 'pura-navaja', 'rol' => 'dueno'],
            ]]);
    }

    /** Owner in one barbershop and customer in another: each one shows with its own role, ordered by name (UP-07). */
    public function test_each_barbershop_shows_the_role_of_the_person_in_it(): void
    {
        $puraNavaja = $this->createBarbershop('Barbería Pura Navaja', 'pura-navaja');
        $fadeLab = $this->createBarbershop('Barbería Fade Lab', 'fade-lab');
        $corte = $this->createBarbershop('Barbería Corte Fino', 'corte-fino');
        $this->grant($this->ana, $puraNavaja, 'owner');
        $this->grant($this->ana, $fadeLab, 'customer');
        $this->grant($this->ana, $corte, 'collaborator');

        $this->getJson(self::ENDPOINT, $this->bearer($this->ana))
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $corte, 'nombre' => 'Barbería Corte Fino', 'enlace' => 'corte-fino', 'rol' => 'colaborador'],
                ['id' => $fadeLab, 'nombre' => 'Barbería Fade Lab', 'enlace' => 'fade-lab', 'rol' => 'cliente'],
                ['id' => $puraNavaja, 'nombre' => 'Barbería Pura Navaja', 'enlace' => 'pura-navaja', 'rol' => 'dueno'],
            ]]);
    }

    /** A deactivated collaborator, or any inactive membership, does not appear. */
    public function test_inactive_memberships_do_not_appear(): void
    {
        $puraNavaja = $this->createBarbershop('Barbería Pura Navaja', 'pura-navaja');
        $fadeLab = $this->createBarbershop('Barbería Fade Lab', 'fade-lab');
        $corte = $this->createBarbershop('Barbería Corte Fino', 'corte-fino');
        $this->grant($this->ana, $puraNavaja, 'collaborator', false);
        $this->grant($this->ana, $fadeLab, 'customer', false);
        $this->grant($this->ana, $corte, 'collaborator');

        $this->getJson(self::ENDPOINT, $this->bearer($this->ana))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $corte);
    }

    /** A person without any role gets an empty list, not an error. */
    public function test_person_without_roles_gets_an_empty_list(): void
    {
        $this->createBarbershop('Barbería Pura Navaja', 'pura-navaja');

        $this->getJson(self::ENDPOINT, $this->bearer($this->ana))->assertOk()->assertExactJson(['data' => []]);
    }

    /** Without a token the answer is the unauthenticated problem of the API. */
    public function test_requires_a_session(): void
    {
        $this->createBarbershop('Barbería Pura Navaja', 'pura-navaja');

        $this->getJson(self::ENDPOINT)
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', rtrim((string) config('app.url'), '/').'/problemas/no-autenticado');
    }

    /** The barbershop is never taken from the query or a header: the list does not change (RD-18, MT-03). */
    public function test_ignores_a_barbershop_sent_by_the_client(): void
    {
        $puraNavaja = $this->createBarbershop('Barbería Pura Navaja', 'pura-navaja');
        $fadeLab = $this->createBarbershop('Barbería Fade Lab', 'fade-lab');
        $this->grant($this->ana, $puraNavaja, 'owner');
        $token = $this->ana->createToken('Celular')->plainTextToken;

        $this->getJson(self::ENDPOINT.'?barberia='.$fadeLab, ['Authorization' => 'Bearer '.$token, 'X-Barbershop-Id' => $fadeLab])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $puraNavaja);
    }

    /** Create an account. */
    private function createUser(string $email): UserModel
    {
        return UserModel::query()->create(['name' => 'Ana Mora', 'email' => $email, 'password' => 'hash']);
    }

    /** Insert a barbershop and return its identifier. */
    private function createBarbershop(string $name, string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $name, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** Give a person a role in a barbershop. */
    private function grant(UserModel $user, string $barbershopId, string $role, bool $active = true): void
    {
        DB::table('memberships')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'barbershop_id' => $barbershopId,
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    /** @return array<string, string> */
    private function bearer(UserModel $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('Celular')->plainTextToken];
    }
}
