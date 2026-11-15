<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Barbershops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/** The owner switches the vouchers and the waitlist on and off, and the customer reads the waitlist switch (RF-09, BO-01, LE-01, MT-04). */
final class BarbershopFeaturesTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private string $puraNavaja;

    private string $fadeLab;

    private UserModel $owner;

    private UserModel $collaborator;

    private UserModel $customer;

    private UserModel $stranger;

    /** Two barbershops; Pura Navaja has an owner, a collaborator and a customer, and Fade Lab has its own owner. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->createBarbershop('pura-navaja');
        $this->fadeLab = $this->createBarbershop('fade-lab');
        $this->owner = $this->createMember('ana@barberhub.test', $this->puraNavaja, 'owner');
        $this->collaborator = $this->createMember('beto@barberhub.test', $this->puraNavaja, 'collaborator');
        $this->customer = $this->createMember('cata@barberhub.test', $this->puraNavaja, 'customer');
        $this->createMember('luis@barberhub.test', $this->fadeLab, 'owner');
        $this->stranger = $this->createMember('dani@barberhub.test', null, null);
    }

    /** Without a session both operations answer the unauthenticated problem. */
    public function test_it_requires_a_session(): void
    {
        $this->getJson($this->url($this->puraNavaja))->assertUnauthorized();
        $this->patchJson($this->url($this->puraNavaja), ['bonos_activos' => true])->assertUnauthorized();
    }

    /** A barbershop that was just registered has the vouchers and the waitlist off. */
    public function test_a_new_barbershop_has_both_switches_off(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->getJson($this->url($this->puraNavaja))->assertOk()->assertExactJson(['data' => ['bonos_activos' => false, 'lista_espera_activa' => false]]);
    }

    /** The owner switches each function on and off, and the change is stored. */
    public function test_owner_switches_each_function_alone(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), ['bonos_activos' => true])->assertOk()
            ->assertExactJson(['data' => ['bonos_activos' => true, 'lista_espera_activa' => false]]);
        $this->patchJson($this->url($this->puraNavaja), ['lista_espera_activa' => true])->assertOk()
            ->assertExactJson(['data' => ['bonos_activos' => true, 'lista_espera_activa' => true]]);
        $this->assertSame([true, true], $this->stored($this->puraNavaja));

        $this->patchJson($this->url($this->puraNavaja), ['bonos_activos' => false, 'lista_espera_activa' => false])->assertOk();
        $this->assertSame([false, false], $this->stored($this->puraNavaja));
        $this->getJson($this->url($this->puraNavaja))->assertExactJson(['data' => ['bonos_activos' => false, 'lista_espera_activa' => false]]);
    }

    /** An empty body changes nothing, and the payment policy and the link are left as they were. */
    public function test_an_empty_change_and_the_rest_of_the_barbershop_stay_as_they_were(): void
    {
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'full', 'payment_gateway' => 'onvo', 'vouchers_enabled' => true]);
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), [])->assertOk()->assertJsonPath('data.bonos_activos', true);
        $this->patchJson($this->url($this->puraNavaja), ['lista_espera_activa' => true])->assertOk();

        $row = DB::table('barbershops')->where('id', $this->puraNavaja)->first();
        $this->assertSame(['full', 'onvo', 'pura-navaja'], [$row?->payment_mode, $row?->payment_gateway, $row?->public_link]);
    }

    /** The change of one barbershop does not touch the other (MT-04). */
    public function test_the_change_does_not_reach_the_other_barbershop(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), ['bonos_activos' => true, 'lista_espera_activa' => true])->assertOk();

        $this->assertSame([false, false], $this->stored($this->fadeLab));
    }

    /**
     * Bodies that are not booleans.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidChanges(): iterable
    {
        yield 'vouchers as text' => [['bonos_activos' => 'quizá']];
        yield 'waitlist as a number' => [['lista_espera_activa' => 5]];
        yield 'vouchers null' => [['bonos_activos' => null]];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalidChanges')]
    public function test_a_value_that_is_not_a_boolean_is_refused_and_changes_nothing(array $body): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), $body)->assertUnprocessable()->assertJsonPath('type', $this->problem('datos-invalidos'));

        $this->assertSame([false, false], $this->stored($this->puraNavaja));
    }

    /** A collaborator and a customer are forbidden and nothing changes (UP-03). */
    public function test_a_collaborator_and_a_customer_are_forbidden(): void
    {
        foreach ([$this->collaborator, $this->customer] as $person) {
            Sanctum::actingAs($person, []);

            $this->getJson($this->url($this->puraNavaja))->assertForbidden()->assertJsonPath('type', $this->problem('prohibido'));
            $this->patchJson($this->url($this->puraNavaja), ['bonos_activos' => true])->assertForbidden();
        }

        $this->assertSame([false, false], $this->stored($this->puraNavaja));
    }

    /** Someone without a role in the barbershop, and the owner of another one, find the same not-found as for a barbershop that does not exist (RD-18). */
    public function test_people_without_a_role_in_the_barbershop_find_nothing(): void
    {
        Sanctum::actingAs($this->stranger, []);
        $missing = $this->getJson($this->url((string) Str::uuid7()));
        $stranger = $this->getJson($this->url($this->puraNavaja));
        $this->patchJson($this->url($this->puraNavaja), ['bonos_activos' => true])->assertNotFound();
        Sanctum::actingAs(UserModel::query()->where('email', 'luis@barberhub.test')->firstOrFail(), []);
        $foreign = $this->patchJson($this->url($this->puraNavaja), ['bonos_activos' => true]);

        $stranger->assertNotFound();
        $foreign->assertNotFound();
        $this->assertSame($missing->json('type'), $stranger->json('type'));
        $this->assertSame($missing->json('detail'), $foreign->json('detail'));
        $this->assertSame([false, false], $this->stored($this->puraNavaja));
    }

    /** The barbershop comes only from the route: a body that names another one changes nothing there (RD-18). */
    public function test_the_barbershop_comes_only_from_the_route(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), ['bonos_activos' => true, 'barberia' => $this->fadeLab, 'barbershop_id' => $this->fadeLab])->assertOk();

        $this->assertSame([true, false], $this->stored($this->puraNavaja));
        $this->assertSame([false, false], $this->stored($this->fadeLab));
    }

    /** The public link tells the customer whether the waitlist is on, and says nothing about the vouchers (RF-09, LE-01). */
    public function test_the_public_link_exposes_the_waitlist_switch(): void
    {
        $this->getJson('/api/v1/enlaces/pura-navaja')->assertOk()->assertJsonPath('data.lista_espera_activa', false)->assertJsonMissingPath('data.bonos_activos');

        Sanctum::actingAs($this->owner, []);
        $this->patchJson($this->url($this->puraNavaja), ['lista_espera_activa' => true, 'bonos_activos' => true])->assertOk();

        $this->getJson('/api/v1/enlaces/pura-navaja')->assertOk()->assertJsonPath('data.lista_espera_activa', true)->assertJsonMissingPath('data.bonos_activos');
        $this->getJson('/api/v1/enlaces/fade-lab')->assertOk()->assertJsonPath('data.lista_espera_activa', false);
    }

    private function url(string $barbershopId): string
    {
        return "/api/v1/barberias/{$barbershopId}/administracion/funciones";
    }

    /** Type of a problem as the API publishes it. */
    private function problem(string $type): string
    {
        return rtrim((string) config('app.url'), '/').'/problemas/'.$type;
    }

    /**
     * The switches as they are stored: vouchers and waitlist.
     *
     * @return array{bool, bool}
     */
    private function stored(string $barbershopId): array
    {
        $row = DB::table('barbershops')->where('id', $barbershopId)->first();
        $this->assertNotNull($row);

        return [(bool) $row->vouchers_enabled, (bool) $row->waitlist_enabled];
    }

    /** Insert a barbershop straight into the table and return its identifier. */
    private function createBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** Create a person, with a role in a barbershop unless none is given. */
    private function createMember(string $email, ?string $barbershopId, ?string $role): UserModel
    {
        $user = UserModel::query()->create(['name' => $email, 'email' => $email, 'password' => 'hash']);

        if ($barbershopId !== null) {
            MembershipModel::query()->create(['user_id' => $user->id, 'barbershop_id' => $barbershopId, 'role' => $role, 'is_active' => true]);
        }

        return $user;
    }
}
