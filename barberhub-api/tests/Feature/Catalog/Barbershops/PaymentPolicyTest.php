<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Barbershops;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/** The owner reads and changes the payment mode, the deposit, the gateway and the free-cancellation deadline (RF-08, PA-01, PA-02, CA-01, MT-04). */
final class PaymentPolicyTest extends TestCase
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
        $this->getJson($this->url($this->puraNavaja))->assertUnauthorized()->assertJsonPath('type', $this->problem('no-autenticado'));
        $this->patchJson($this->url($this->puraNavaja), ['plazo_cancelacion_horas' => 12])->assertUnauthorized();
    }

    /** A barbershop that was just registered charges nothing, has no gateway and 24 hours of deadline. */
    public function test_a_new_barbershop_charges_nothing(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->getJson($this->url($this->puraNavaja))->assertOk()->assertExactJson(['data' => [
            'modalidad' => 'sin_pago',
            'porcentaje_deposito' => null,
            'plazo_cancelacion_horas' => 24,
            'pasarela' => null,
        ]]);
    }

    /** The owner sets a deposit of 30 % with ONVO Pay and 4 hours, and the change is stored (PA-01, PA-02, CA-01). */
    public function test_owner_sets_a_deposit(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), ['modalidad' => 'deposito', 'porcentaje_deposito' => 30, 'plazo_cancelacion_horas' => 4, 'pasarela' => 'onvo'])
            ->assertOk()
            ->assertExactJson(['data' => ['modalidad' => 'deposito', 'porcentaje_deposito' => 30, 'plazo_cancelacion_horas' => 4, 'pasarela' => 'onvo']]);

        $this->getJson($this->url($this->puraNavaja))->assertOk()->assertJsonPath('data.porcentaje_deposito', 30);
        $this->assertSame(['deposit', 30, 4, 'onvo'], $this->storedPolicy($this->puraNavaja));
    }

    /** The owner sets full payment with PayPal. */
    public function test_owner_sets_full_payment(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), ['modalidad' => 'total', 'pasarela' => 'paypal'])
            ->assertOk()
            ->assertExactJson(['data' => ['modalidad' => 'total', 'porcentaje_deposito' => null, 'plazo_cancelacion_horas' => 24, 'pasarela' => 'paypal']]);
    }

    /** A field that does not come keeps its value, and leaving the deposit drops its percentage. */
    public function test_a_partial_change_keeps_the_rest_and_leaving_the_deposit_drops_the_percentage(): void
    {
        Sanctum::actingAs($this->owner, []);
        $this->patchJson($this->url($this->puraNavaja), ['modalidad' => 'deposito', 'porcentaje_deposito' => 30, 'pasarela' => 'onvo'])->assertOk();

        $this->patchJson($this->url($this->puraNavaja), ['plazo_cancelacion_horas' => 12])->assertOk()
            ->assertExactJson(['data' => ['modalidad' => 'deposito', 'porcentaje_deposito' => 30, 'plazo_cancelacion_horas' => 12, 'pasarela' => 'onvo']]);
        $this->patchJson($this->url($this->puraNavaja), ['modalidad' => 'total'])->assertOk()
            ->assertExactJson(['data' => ['modalidad' => 'total', 'porcentaje_deposito' => null, 'plazo_cancelacion_horas' => 12, 'pasarela' => 'onvo']]);
        $this->patchJson($this->url($this->puraNavaja), ['modalidad' => 'sin_pago'])->assertOk()
            ->assertJsonPath('data.pasarela', 'onvo');
    }

    /** The change of one barbershop does not touch the other (MT-04). */
    public function test_the_change_does_not_reach_the_other_barbershop(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), ['modalidad' => 'total', 'pasarela' => 'paypal', 'plazo_cancelacion_horas' => 2])->assertOk();

        $this->assertSame(['none', null, 24, null], $this->storedPolicy($this->fadeLab));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidChanges(): iterable
    {
        yield 'deposit of 5 %' => [['modalidad' => 'deposito', 'porcentaje_deposito' => 5, 'pasarela' => 'onvo'], 'porcentaje_deposito'];
        yield 'deposit of 120 %' => [['modalidad' => 'deposito', 'porcentaje_deposito' => 120, 'pasarela' => 'onvo'], 'porcentaje_deposito'];
        yield 'deposit without percentage' => [['modalidad' => 'deposito', 'pasarela' => 'onvo'], ''];
        yield 'percentage with full payment' => [['modalidad' => 'total', 'porcentaje_deposito' => 30, 'pasarela' => 'onvo'], ''];
        yield 'deposit without gateway' => [['modalidad' => 'deposito', 'porcentaje_deposito' => 30], ''];
        yield 'full payment without gateway' => [['modalidad' => 'total'], ''];
        yield 'deadline over 720 hours' => [['plazo_cancelacion_horas' => 721], 'plazo_cancelacion_horas'];
        yield 'negative deadline' => [['plazo_cancelacion_horas' => -1], 'plazo_cancelacion_horas'];
        yield 'unknown mode' => [['modalidad' => 'mitad'], 'modalidad'];
        yield 'unknown gateway' => [['pasarela' => 'stripe'], 'pasarela'];
    }

    /**
     * An invalid value or combination answers 422 and changes nothing.
     *
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidChanges')]
    public function test_an_invalid_change_is_rejected_and_stores_nothing(array $payload, string $field): void
    {
        Sanctum::actingAs($this->owner, []);

        $response = $this->patchJson($this->url($this->puraNavaja), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', $this->problem('datos-invalidos'));

        if ($field !== '') {
            $response->assertJsonValidationErrors($field, 'errores');
        }
        $this->assertSame(['none', null, 24, null], $this->storedPolicy($this->puraNavaja));
    }

    /** Only the owner reads or changes it: a collaborator and a customer get 403 and a stranger or another barbershop's owner get the 404 of a missing barbershop. */
    public function test_only_the_owner_can_use_it(): void
    {
        foreach ([$this->collaborator, $this->customer] as $member) {
            Sanctum::actingAs($member, []);

            $this->getJson($this->url($this->puraNavaja))->assertForbidden()->assertJsonPath('type', $this->problem('prohibido'));
            $this->patchJson($this->url($this->puraNavaja), ['plazo_cancelacion_horas' => 1])->assertForbidden()->assertJsonPath('type', $this->problem('prohibido'));
        }

        $missing = (string) Str::uuid7();
        foreach ([$this->stranger, $this->owner] as $outsider) {
            Sanctum::actingAs($outsider, []);
            $url = $outsider === $this->owner ? $this->fadeLab : $this->puraNavaja;

            foreach (['getJson' => [], 'patchJson' => ['plazo_cancelacion_horas' => 1]] as $method => $payload) {
                $foreign = $this->{$method}($this->url($url), $payload)->assertNotFound();
                $nobody = $this->{$method}($this->url($missing), $payload)->assertNotFound();
                $this->assertSame($this->withoutInstance($nobody->json()), $this->withoutInstance($foreign->json()));
            }
        }

        $this->assertSame(['none', null, 24, null], $this->storedPolicy($this->puraNavaja));
        $this->assertSame(['none', null, 24, null], $this->storedPolicy($this->fadeLab));
    }

    /**
     * The database rejects what the domain rejects, even if a statement skips the application (PA-01, PA-02, CA-01).
     *
     * @param  array<string, mixed>  $values
     */
    #[DataProvider('invalidRows')]
    public function test_the_database_rejects_an_invalid_policy(string $check, array $values): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage($check);

        DB::table('barbershops')->where('id', $this->puraNavaja)->update($values);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function invalidRows(): iterable
    {
        yield 'unknown mode' => ['barbershops_payment_mode_check', ['payment_mode' => 'half', 'payment_gateway' => 'onvo']];
        yield 'deposit of 5 %' => ['barbershops_deposit_percentage_check', ['payment_mode' => 'deposit', 'deposit_percentage' => 5, 'payment_gateway' => 'onvo']];
        yield 'deposit of 120 %' => ['barbershops_deposit_percentage_check', ['payment_mode' => 'deposit', 'deposit_percentage' => 120, 'payment_gateway' => 'onvo']];
        yield 'deposit without percentage' => ['barbershops_deposit_percentage_mode_check', ['payment_mode' => 'deposit', 'payment_gateway' => 'onvo']];
        yield 'percentage without deposit' => ['barbershops_deposit_percentage_mode_check', ['payment_mode' => 'full', 'deposit_percentage' => 30, 'payment_gateway' => 'onvo']];
        yield 'deadline over 720 hours' => ['barbershops_cancellation_deadline_check', ['cancellation_deadline_hours' => 721]];
        yield 'negative deadline' => ['barbershops_cancellation_deadline_check', ['cancellation_deadline_hours' => -1]];
        yield 'full payment without gateway' => ['barbershops_payment_gateway_required_check', ['payment_mode' => 'full']];
    }

    /** Address of the payment policy of a barbershop. */
    private function url(string $barbershopId): string
    {
        return "/api/v1/barberias/{$barbershopId}/administracion/cobro";
    }

    /** Type of a problem as the API publishes it. */
    private function problem(string $type): string
    {
        return rtrim((string) config('app.url'), '/').'/problemas/'.$type;
    }

    /**
     * A problem document without the identifier of the request, which is different in each answer.
     *
     * @param  array<string, mixed>  $problem
     * @return array<string, mixed>
     */
    private function withoutInstance(array $problem): array
    {
        unset($problem['instance']);

        return $problem;
    }

    /**
     * The policy columns of a barbershop as they are stored: mode, percentage, deadline and gateway.
     *
     * @return array{string, int|null, int, string|null}
     */
    private function storedPolicy(string $barbershopId): array
    {
        $row = DB::table('barbershops')->where('id', $barbershopId)->first();
        $this->assertNotNull($row);

        return [$row->payment_mode, $row->deposit_percentage === null ? null : (int) $row->deposit_percentage, (int) $row->cancellation_deadline_hours, $row->payment_gateway];
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
