<?php

declare(strict_types=1);

namespace Tests\Feature\Vouchers\VoucherDefinitions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\Support\Vouchers\SeedsVouchers;
use Tests\TestCase;

/** The owner defines, edits, withdraws and reads the vouchers of the barbershop (RF-26, BO-01, MT-04, UP-03). */
final class ManageVoucherDefinitionsTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;
    use SeedsVouchers;

    private string $puraNavaja;

    private string $fadeLab;

    private string $classicCut;

    private string $fade;

    private string $beard;

    private string $foreignService;

    private UserModel $owner;

    private UserModel $foreignOwner;

    private UserModel $collaborator;

    private UserModel $customer;

    private UserModel $stranger;

    private string $customerMembership;

    /** Pura Navaja has an owner, a collaborator, a customer and three services; Fade Lab has its own owner and one service. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->createBarbershop('pura-navaja');
        $this->fadeLab = $this->createBarbershop('fade-lab');
        $this->owner = $this->createMember('ana@barberhub.test', $this->puraNavaja, 'owner');
        $this->collaborator = $this->createMember('beto@barberhub.test', $this->puraNavaja, 'collaborator');
        $this->customer = $this->createMember('cata@barberhub.test', $this->puraNavaja, 'customer');
        $this->foreignOwner = $this->createMember('luis@barberhub.test', $this->fadeLab, 'owner');
        $this->stranger = $this->createMember('dani@barberhub.test', null, null);
        $this->customerMembership = (string) MembershipModel::query()->where('user_id', $this->customer->id)->value('id');
        $this->classicCut = $this->createService($this->puraNavaja, 'Corte clásico');
        $this->fade = $this->createService($this->puraNavaja, 'Corte degradado');
        $this->beard = $this->createService($this->puraNavaja, 'Barba');
        $this->foreignService = $this->createService($this->fadeLab, 'Corte clásico');
    }

    /** Without a session the four operations answer the unauthenticated problem. */
    public function test_administration_requires_a_session(): void
    {
        $id = (string) Str::uuid7();

        $this->getJson($this->url($this->puraNavaja))->assertUnauthorized()->assertJsonPath('type', $this->problem('no-autenticado'));
        $this->postJson($this->url($this->puraNavaja), $this->payload())->assertUnauthorized();
        $this->getJson($this->url($this->puraNavaja, $id))->assertUnauthorized();
        $this->patchJson($this->url($this->puraNavaja, $id), ['precio' => 1000])->assertUnauthorized();
    }

    /** "Bono 5 cortes" is created on sale and covers only the classic cut and the fade, and nothing else (RF-26, BO-01). */
    public function test_owner_creates_a_voucher_that_covers_exactly_its_services(): void
    {
        Sanctum::actingAs($this->owner, []);

        $response = $this->postJson($this->url($this->puraNavaja), $this->payload())->assertCreated();

        $id = $response->json('data.id');
        $this->assertTrue(Str::isUuid($id, 7));
        $response->assertExactJson(['data' => [
            'id' => $id,
            'nombre' => 'Bono 5 cortes',
            'sesiones' => 5,
            'precio' => 30000,
            'vigencia_dias' => 90,
            'servicios' => [$this->classicCut, $this->fade],
            'activo' => true,
        ]]);
        $response->assertHeader('Location', url($this->url($this->puraNavaja, $id)));

        $this->scopeQueriesTo($this->puraNavaja);
        $row = DB::table('voucher_definitions')->sole();
        $this->assertSame([$this->puraNavaja, 'Bono 5 cortes', 5, 30000, 90, true], [$row->barbershop_id, $row->name, $row->sessions_count, $row->price, $row->validity_days, $row->is_active]);
        $this->assertEqualsCanonicalizing([$this->classicCut, $this->fade], DB::table('voucher_definition_services')->where('voucher_definition_id', $id)->pluck('service_id')->all());
        $this->assertNotContains($this->beard, DB::table('voucher_definition_services')->pluck('service_id')->all());
        $this->getJson($this->url($this->puraNavaja, $id))->assertOk()->assertJsonPath('data.activo', true);
    }

    /**
     * Bodies that break a rule and the field the error names.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): array
    {
        return [
            'zero sessions' => [['sesiones' => 0], 'sesiones'],
            'negative sessions' => [['sesiones' => -3], 'sesiones'],
            'sessions above the column' => [['sesiones' => 32768], 'sesiones'],
            'zero price' => [['precio' => 0], 'precio'],
            'negative price' => [['precio' => -5], 'precio'],
            'price with decimals' => [['precio' => 100.5], 'precio'],
            'zero validity' => [['vigencia_dias' => 0], 'vigencia_dias'],
            'validity above the column' => [['vigencia_dias' => 32768], 'vigencia_dias'],
            'no services' => [['servicios' => []], 'servicios'],
            'repeated services' => [['servicios' => ['{classic}', '{classic}']], 'servicios.1'],
            'service that is not an identifier' => [['servicios' => ['corte']], 'servicios.0'],
            'blank name' => [['nombre' => '   '], 'nombre'],
            'name too long' => [['nombre' => 'B'.str_repeat('o', 120)], 'nombre'],
        ];
    }

    /**
     * A body that breaks a rule is refused with the field it breaks, and nothing is stored.
     *
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('invalidBodies')]
    public function test_a_body_that_breaks_a_rule_is_refused_naming_the_field(array $change, string $field): void
    {
        Sanctum::actingAs($this->owner, []);
        $body = array_replace($this->payload(), json_decode(str_replace('{classic}', $this->classicCut, (string) json_encode($change)), true));

        $this->postJson($this->url($this->puraNavaja), $body)
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', $this->problem('datos-invalidos'))
            ->assertJsonValidationErrorFor($field, 'errores');

        $this->assertSame(0, $this->countDefinitions($this->puraNavaja));
    }

    /** A body without its fields names each one. */
    public function test_an_empty_body_names_every_required_field(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->postJson($this->url($this->puraNavaja), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre', 'sesiones', 'precio', 'vigencia_dias', 'servicios'], 'errores');
    }

    /** A service of another barbershop is refused like one that does not exist: its existence is never revealed (MT-04). */
    public function test_a_service_of_another_barbershop_is_refused_without_revealing_it(): void
    {
        Sanctum::actingAs($this->owner, []);

        $foreign = $this->postJson($this->url($this->puraNavaja), $this->payload([$this->classicCut, $this->foreignService]))
            ->assertUnprocessable()
            ->assertJsonPath('type', $this->problem('datos-invalidos'))
            ->assertJsonValidationErrorFor('servicios.1', 'errores');
        $missing = $this->postJson($this->url($this->puraNavaja), $this->payload([$this->classicCut, (string) Str::uuid7()]))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('servicios.1', 'errores');

        $this->assertSame($missing->json('errores'), $foreign->json('errores'));
        $this->assertSame($missing->json('detail'), $foreign->json('detail'));
        $this->assertSame(0, $this->countDefinitions($this->puraNavaja));
    }

    /** Withdrawing a voucher does not touch the vouchers customers already bought: balance, dates, price and status stay (RF-26). */
    public function test_withdrawing_a_voucher_leaves_the_purchased_vouchers_as_they_were(): void
    {
        Sanctum::actingAs($this->owner, []);
        $definition = $this->postJson($this->url($this->puraNavaja), $this->payload())->json('data.id');
        $purchased = $this->insertPurchasedVoucher($this->puraNavaja, $this->customerMembership, $definition);
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('purchased_vouchers')->where('id', $purchased)->update(['remaining_sessions' => 3]);
        $before = $this->purchasedState($purchased);

        $this->patchJson($this->url($this->puraNavaja, $definition), ['activo' => false])
            ->assertOk()
            ->assertJsonPath('data.activo', false);

        $this->assertEquals($before, $this->purchasedState($purchased));
        $this->assertSame(3, $before['row']['remaining_sessions']);
        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertFalse((bool) DB::table('voucher_definitions')->where('id', $definition)->value('is_active'));
    }

    /** Changing the price changes the voucher on sale and not the one already bought, which keeps what its customer paid (BO-01). */
    public function test_changing_the_price_does_not_change_a_purchased_voucher(): void
    {
        Sanctum::actingAs($this->owner, []);
        $definition = $this->postJson($this->url($this->puraNavaja), $this->payload())->json('data.id');
        $purchased = $this->insertPurchasedVoucher($this->puraNavaja, $this->customerMembership, $definition);
        $before = $this->purchasedState($purchased);

        $this->patchJson($this->url($this->puraNavaja, $definition), ['precio' => 36000, 'nombre' => 'Bono 5 cortes 2031'])
            ->assertOk()
            ->assertJsonPath('data.precio', 36000)
            ->assertJsonPath('data.nombre', 'Bono 5 cortes 2031');

        $after = $this->purchasedState($purchased);
        $this->assertEquals($before, $after);
        $this->assertSame([30000, 'Bono 5 cortes'], [$after['row']['price'], $after['row']['name']]);
    }

    /** Once sold, the sessions, the validity and the services cannot change: 409 `bono-con-ventas` and nothing is written. */
    public function test_a_sold_voucher_cannot_change_sessions_validity_or_services(): void
    {
        Sanctum::actingAs($this->owner, []);
        $definition = $this->postJson($this->url($this->puraNavaja), $this->payload())->json('data.id');
        $this->insertPurchasedVoucher($this->puraNavaja, $this->customerMembership, $definition, 'pending');

        foreach ([['sesiones' => 10], ['vigencia_dias' => 30], ['servicios' => [$this->classicCut]], ['servicios' => [$this->classicCut, $this->fade, $this->beard]], ['nombre' => 'Otro', 'precio' => 1, 'sesiones' => 6]] as $change) {
            $this->patchJson($this->url($this->puraNavaja, $definition), $change)
                ->assertConflict()
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', $this->problem('bono-con-ventas'))
                ->assertJsonPath('status', 409);
        }

        $this->getJson($this->url($this->puraNavaja, $definition))->assertExactJson(['data' => [
            'id' => $definition, 'nombre' => 'Bono 5 cortes', 'sesiones' => 5, 'precio' => 30000, 'vigencia_dias' => 90,
            'servicios' => [$this->classicCut, $this->fade], 'activo' => true,
        ]]);
    }

    /** A sold voucher still changes its name and price, withdraws and comes back, and the same terms are not a change. */
    public function test_a_sold_voucher_still_changes_name_price_and_sale_flag(): void
    {
        Sanctum::actingAs($this->owner, []);
        $definition = $this->postJson($this->url($this->puraNavaja), $this->payload())->json('data.id');
        $this->insertPurchasedVoucher($this->puraNavaja, $this->customerMembership, $definition);
        $url = $this->url($this->puraNavaja, $definition);

        $this->patchJson($url, ['nombre' => 'Bono cinco cortes', 'precio' => 32000, 'sesiones' => 5, 'vigencia_dias' => 90, 'servicios' => [$this->fade, $this->classicCut], 'activo' => false])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Bono cinco cortes')
            ->assertJsonPath('data.precio', 32000)
            ->assertJsonPath('data.activo', false);
        $this->patchJson($url, ['activo' => true])->assertOk()->assertJsonPath('data.activo', true);
    }

    /** Without sales everything is editable: the numbers, the services and the sale flag, and the covered services are replaced. */
    public function test_a_voucher_without_sales_is_edited_freely(): void
    {
        Sanctum::actingAs($this->owner, []);
        $definition = $this->postJson($this->url($this->puraNavaja), $this->payload())->json('data.id');

        $this->patchJson($this->url($this->puraNavaja, $definition), [
            'nombre' => 'Bono 10 cortes y barba', 'sesiones' => 10, 'precio' => 55000, 'vigencia_dias' => 180, 'servicios' => [$this->fade, $this->beard],
        ])->assertOk()->assertExactJson(['data' => [
            'id' => $definition, 'nombre' => 'Bono 10 cortes y barba', 'sesiones' => 10, 'precio' => 55000, 'vigencia_dias' => 180,
            'servicios' => [$this->fade, $this->beard], 'activo' => true,
        ]]);

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertEqualsCanonicalizing([$this->fade, $this->beard], DB::table('voucher_definition_services')->where('voucher_definition_id', $definition)->pluck('service_id')->all());
    }

    /** An edit that breaks a rule is refused, and one with a service of another barbershop is refused without revealing it. */
    public function test_an_invalid_edit_is_refused_and_changes_nothing(): void
    {
        Sanctum::actingAs($this->owner, []);
        $definition = $this->postJson($this->url($this->puraNavaja), $this->payload())->json('data.id');
        $url = $this->url($this->puraNavaja, $definition);

        $this->patchJson($url, ['sesiones' => 0])->assertUnprocessable()->assertJsonValidationErrorFor('sesiones', 'errores');
        $this->patchJson($url, ['precio' => 0])->assertUnprocessable()->assertJsonValidationErrorFor('precio', 'errores');
        $this->patchJson($url, ['servicios' => []])->assertUnprocessable()->assertJsonValidationErrorFor('servicios', 'errores');
        $this->patchJson($url, ['servicios' => [$this->fade, $this->fade]])->assertUnprocessable()->assertJsonValidationErrorFor('servicios.1', 'errores');
        $this->patchJson($url, ['nombre' => 'Nuevo', 'servicios' => [$this->foreignService]])->assertUnprocessable()->assertJsonValidationErrorFor('servicios.0', 'errores');

        $this->getJson($url)->assertJsonPath('data.nombre', 'Bono 5 cortes')->assertJsonPath('data.sesiones', 5);
    }

    /** The list brings the vouchers on sale and the withdrawn ones, by name, with their services, and only those of the barbershop. */
    public function test_owner_lists_the_vouchers_of_the_barbershop_only(): void
    {
        Sanctum::actingAs($this->owner, []);
        $second = $this->postJson($this->url($this->puraNavaja), $this->payload([$this->beard], name: 'Bono barba'))->json('data.id');
        $first = $this->postJson($this->url($this->puraNavaja), $this->payload())->json('data.id');
        $this->patchJson($this->url($this->puraNavaja, $second), ['activo' => false])->assertOk();
        $this->insertVoucherDefinition($this->fadeLab, [$this->foreignService], 'Bono de Fade Lab');

        $response = $this->getJson($this->url($this->puraNavaja))->assertOk();

        $this->assertSame([$first, $second], $response->json('data.*.id'));
        $this->assertSame([true, false], $response->json('data.*.activo'));
        $this->assertSame([$this->beard], $response->json('data.1.servicios'));
    }

    /** A barbershop without vouchers gets an empty list. */
    public function test_owner_of_a_barbershop_without_vouchers_gets_an_empty_list(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->getJson($this->url($this->puraNavaja))->assertOk()->assertExactJson(['data' => []]);
    }

    /** A collaborator and a customer of the barbershop are forbidden on every operation; nothing changes (UP-03). */
    public function test_a_collaborator_and_a_customer_are_forbidden(): void
    {
        $definition = $this->insertVoucherDefinition($this->puraNavaja, [$this->classicCut]);

        foreach ([$this->collaborator, $this->customer] as $member) {
            Sanctum::actingAs($member, []);

            $this->getJson($this->url($this->puraNavaja))->assertForbidden()->assertJsonPath('type', $this->problem('prohibido'));
            $this->postJson($this->url($this->puraNavaja), $this->payload())->assertForbidden();
            $this->getJson($this->url($this->puraNavaja, $definition))->assertForbidden();
            $this->patchJson($this->url($this->puraNavaja, $definition), ['activo' => false])->assertForbidden();
        }

        $this->assertSame(1, $this->countDefinitions($this->puraNavaja));
        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertTrue((bool) DB::table('voucher_definitions')->where('id', $definition)->value('is_active'));
    }

    /** Someone with no role in the barbershop, and the owner of another one, find nothing, as if the barbershop did not exist (RD-18). */
    public function test_people_without_a_role_in_the_barbershop_find_nothing(): void
    {
        $definition = $this->insertVoucherDefinition($this->puraNavaja, [$this->classicCut]);

        foreach ([$this->stranger, $this->foreignOwner] as $person) {
            Sanctum::actingAs($person, []);

            $this->getJson($this->url($this->puraNavaja))->assertNotFound()->assertJsonPath('type', $this->problem('no-encontrado'));
            $this->postJson($this->url($this->puraNavaja), $this->payload())->assertNotFound();
            $this->getJson($this->url($this->puraNavaja, $definition))->assertNotFound();
            $this->patchJson($this->url($this->puraNavaja, $definition), ['activo' => false])->assertNotFound();
        }

        $this->assertSame(1, $this->countDefinitions($this->puraNavaja));
        $this->assertSame(0, $this->countDefinitions($this->fadeLab));
    }

    /** A voucher of another barbershop answers the same as one that does not exist, even to an owner (MT-04). */
    public function test_a_voucher_of_another_barbershop_is_not_found_with_the_same_body(): void
    {
        Sanctum::actingAs($this->owner, []);
        $foreign = $this->insertVoucherDefinition($this->fadeLab, [$this->foreignService]);
        $missing = (string) Str::uuid7();

        $notFound = $this->getJson($this->url($this->puraNavaja, $missing))->assertNotFound();
        $this->assertSame($this->bodyWithoutInstance($notFound->json()), $this->bodyWithoutInstance($this->getJson($this->url($this->puraNavaja, $foreign))->assertNotFound()->json()));
        $this->patchJson($this->url($this->puraNavaja, $foreign), ['activo' => false])->assertNotFound();

        $this->scopeQueriesTo($this->fadeLab);
        $this->assertTrue((bool) DB::table('voucher_definitions')->where('id', $foreign)->value('is_active'));
    }

    /** A person who owns a barbershop gets no access to another one by naming its identifier in the body (RD-18). */
    public function test_the_barbershop_comes_only_from_the_route(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->postJson($this->url($this->puraNavaja), [...$this->payload(), 'barberia' => $this->fadeLab, 'barbershop_id' => $this->fadeLab])->assertCreated();

        $this->assertSame(1, $this->countDefinitions($this->puraNavaja));
        $this->assertSame(0, $this->countDefinitions($this->fadeLab));
    }

    /** Address of the vouchers the owner administers, or of one of them. */
    private function url(string $barbershopId, ?string $definitionId = null): string
    {
        $url = "/api/v1/barberias/{$barbershopId}/administracion/bonos";

        return $definitionId === null ? $url : "{$url}/{$definitionId}";
    }

    /** Type of a problem as the API publishes it. */
    private function problem(string $type): string
    {
        return rtrim((string) config('app.url'), '/').'/problemas/'.$type;
    }

    /**
     * A valid body for "Bono 5 cortes": 5 sessions for ₡30.000 that last 90 days, covering the classic cut and the fade.
     *
     * @param  list<string>|null  $services
     * @return array{nombre: string, sesiones: int, precio: int, vigencia_dias: int, servicios: list<string>}
     */
    private function payload(?array $services = null, string $name = 'Bono 5 cortes'): array
    {
        return ['nombre' => $name, 'sesiones' => 5, 'precio' => 30000, 'vigencia_dias' => 90, 'servicios' => $services ?? [$this->classicCut, $this->fade]];
    }

    /**
     * The body of a problem without the request identifier, which is different on every answer.
     *
     * @param  array<string, mixed>  $problem
     * @return array<string, mixed>
     */
    private function bodyWithoutInstance(array $problem): array
    {
        unset($problem['instance']);

        return $problem;
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

    /** Insert a service of a barbershop straight into the table and return its identifier. */
    private function createService(string $barbershopId, string $name): string
    {
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('services')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'name' => $name, 'duration_minutes' => 30, 'price' => 7000]);

        return $id;
    }

    /** Number of voucher definitions a barbershop has, read as the barbershop. */
    private function countDefinitions(string $barbershopId): int
    {
        $this->scopeQueriesTo($barbershopId);

        return DB::table('voucher_definitions')->count();
    }

    /**
     * The row of a purchased voucher of Pura Navaja with its record, as they are in the database.
     *
     * @return array{row: array<string, mixed>, movements: list<array<string, mixed>>}
     */
    private function purchasedState(string $purchasedId): array
    {
        $this->scopeQueriesTo($this->puraNavaja);

        return [
            'row' => (array) DB::table('purchased_vouchers')->where('id', $purchasedId)->sole(),
            'movements' => array_values(DB::table('voucher_movements')->where('purchased_voucher_id', $purchasedId)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all()),
        ];
    }
}
