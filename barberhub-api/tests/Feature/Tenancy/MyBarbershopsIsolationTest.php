<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/** The list of one person never carries the barbershops or the memberships of another (RF-43, RF-44, UP-06, MT-02). */
final class MyBarbershopsIsolationTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    /** Ana owns Pura Navaja and Luis owns Fade Lab and is a customer in Pura Navaja: each list holds only their own roles. */
    public function test_the_list_of_a_person_holds_only_their_own_memberships(): void
    {
        $ana = UserModel::query()->create(['name' => 'Ana Mora', 'email' => 'ana@barberhub.test', 'password' => 'hash']);
        $luis = UserModel::query()->create(['name' => 'Luis Vega', 'email' => 'luis@barberhub.test', 'password' => 'hash']);
        $puraNavaja = $this->createBarbershop('Barbería Pura Navaja', 'pura-navaja');
        $fadeLab = $this->createBarbershop('Barbería Fade Lab', 'fade-lab');
        $this->createBarbershop('Barbería Corte Fino', 'corte-fino');
        $this->grant($ana, $puraNavaja, 'owner');
        $this->grant($luis, $fadeLab, 'owner');
        $this->grant($luis, $puraNavaja, 'customer');

        $this->getJson('/api/v1/mis-barberias', $this->bearer($ana))
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $puraNavaja, 'nombre' => 'Barbería Pura Navaja', 'enlace' => 'pura-navaja', 'rol' => 'dueno'],
            ]]);

        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/mis-barberias', $this->bearer($luis))
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $fadeLab, 'nombre' => 'Barbería Fade Lab', 'enlace' => 'fade-lab', 'rol' => 'dueno'],
                ['id' => $puraNavaja, 'nombre' => 'Barbería Pura Navaja', 'enlace' => 'pura-navaja', 'rol' => 'cliente'],
            ]]);
    }

    /** The barbershop a previous request left in the connection does not widen or narrow the list. */
    public function test_a_barbershop_left_in_the_connection_changes_nothing(): void
    {
        $ana = UserModel::query()->create(['name' => 'Ana Mora', 'email' => 'ana@barberhub.test', 'password' => 'hash']);
        $puraNavaja = $this->createBarbershop('Barbería Pura Navaja', 'pura-navaja');
        $fadeLab = $this->createBarbershop('Barbería Fade Lab', 'fade-lab');
        $this->grant($ana, $puraNavaja, 'owner');
        $this->grant($ana, $fadeLab, 'customer');

        DB::transaction(fn () => DB::statement('select set_config(?, ?, true)', ['app.barbershop_id', $fadeLab]));

        $this->getJson('/api/v1/mis-barberias', $this->bearer($ana))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /** @return array<string, string> */
    private function bearer(UserModel $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('Celular')->plainTextToken];
    }

    /** Insert a barbershop and return its identifier. */
    private function createBarbershop(string $name, string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $name, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** Give a person an active role in a barbershop. */
    private function grant(UserModel $user, string $barbershopId, string $role): void
    {
        DB::table('memberships')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'barbershop_id' => $barbershopId,
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
