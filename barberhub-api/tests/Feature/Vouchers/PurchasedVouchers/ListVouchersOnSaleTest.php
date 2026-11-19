<?php

declare(strict_types=1);

namespace Tests\Feature\Vouchers\PurchasedVouchers;

use Illuminate\Support\Facades\DB;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Tenancy\BuildsAccessScenario;
use Tests\TestCase;

/**
 * The customer reads the vouchers a barbershop sells and what each one covers, before buying (RF-27, UP-09). Pura Navaja has one
 * service, "Degradado", and Fade Lab another, "Corte"; Ana and Luis are customers of Pura Navaja and Bea of Fade Lab.
 */
final class ListVouchersOnSaleTest extends TestCase
{
    use BuildsAccessScenario;
    use RefreshDatabaseAsOwner;

    private string $beard;

    /** Both barbershops are built, and Pura Navaja gets a second service. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->buildAccessScenario();
        $this->scopeQueriesTo($this->puraNavaja);
        $this->beard = $this->insertService($this->puraNavaja, 'Barba', 30, 4000);
    }

    /** The list carries the terms of each voucher on sale, the price with VAT included and the services it covers by id and name (RF-27, BO-06). */
    public function test_the_list_gives_each_voucher_with_its_terms_and_covered_services(): void
    {
        $combo = $this->insertVoucherDefinition($this->puraNavaja, [$this->degradado, $this->beard], 'Bono combo', 8, 52000, 120);
        // The scenario already sells "Bono 5 cortes", which covers the Degradado.
        $cuts = $this->resources['bono'];

        $response = $this->sendAs('customer', 'GET', $this->onSale($this->puraNavaja))->assertOk();

        $response->assertJsonPath('data.0', [
            'id' => $cuts, 'nombre' => 'Bono 5 cortes', 'sesiones' => 5, 'vigencia_dias' => 90, 'precio' => 30000,
            'servicios' => [['id' => $this->degradado, 'nombre' => 'Degradado']],
        ]);
        $response->assertJsonPath('data.1', [
            'id' => $combo, 'nombre' => 'Bono combo', 'sesiones' => 8, 'vigencia_dias' => 120, 'precio' => 52000,
            // Ordered by name, not by the order they were attached.
            'servicios' => [['id' => $this->beard, 'nombre' => 'Barba'], ['id' => $this->degradado, 'nombre' => 'Degradado']],
        ]);
        $response->assertJsonPath('meta', ['pagina' => 1, 'por_pagina' => 15, 'total' => 2, 'ultima_pagina' => 1, 'bonos_activos' => true, 'pasarela' => null, 'metodos_de_pago' => []]);
        $this->assertSame(['id', 'nombre', 'sesiones', 'vigencia_dias', 'precio', 'servicios'], array_keys($response->json('data.0')));
    }

    /** The list is paged as the rest of the readings are. */
    public function test_the_list_is_paged(): void
    {
        // With the "Bono 5 cortes" of the scenario, the order by name is: that one, A and B.
        $first = $this->insertVoucherDefinition($this->puraNavaja, [$this->degradado], 'Bono A');
        $second = $this->insertVoucherDefinition($this->puraNavaja, [$this->degradado], 'Bono B');

        $page = $this->sendAs('customer', 'GET', $this->onSale($this->puraNavaja).'?por_pagina=1&pagina=2')->assertOk();

        $this->assertSame([$first], $page->json('data.*.id'));
        $page->assertJsonPath('meta.total', 3)->assertJsonPath('meta.ultima_pagina', 3)->assertJsonPath('meta.pagina', 2)->assertJsonPath('meta.por_pagina', 1);
        $this->assertNotContains($second, $page->json('data.*.id'));
    }

    /** A voucher withdrawn from sale is not listed, and listing it again once reactivated brings it back (RF-26). */
    public function test_a_withdrawn_voucher_is_not_listed(): void
    {
        $kept = $this->resources['bono'];
        $withdrawn = $this->insertVoucherDefinition($this->puraNavaja, [$this->degradado], 'Bono retirado', active: false);

        $this->assertSame([$kept], $this->sendAs('customer', 'GET', $this->onSale($this->puraNavaja))->assertOk()->json('data.*.id'));
        $this->sendAs('customer', 'GET', $this->onSale($this->puraNavaja))->assertJsonPath('meta.total', 1);

        DB::table('voucher_definitions')->where('id', $withdrawn)->update(['is_active' => true]);
        $this->assertEqualsCanonicalizing([$kept, $withdrawn], $this->sendAs('customer', 'GET', $this->onSale($this->puraNavaja))->json('data.*.id'));
    }

    /** With the vouchers switched off the list is empty and says so, even if there are vouchers on sale (RF-09, BO-01). */
    public function test_with_the_vouchers_switched_off_the_list_is_empty_and_says_so(): void
    {
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['vouchers_enabled' => false]);

        $response = $this->sendAs('customer', 'GET', $this->onSale($this->puraNavaja))->assertOk();

        $response->assertJsonPath('data', [])->assertJsonPath('meta.bonos_activos', false)->assertJsonPath('meta.total', 0);
    }

    /** With ONVO the list says `onvo` and both of its methods, as a reservation of the same barbershop does (RF-20, PA-02). */
    public function test_a_barbershop_that_collects_with_onvo_lists_onvo_and_its_methods(): void
    {
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'deposit', 'deposit_percentage' => 30, 'payment_gateway' => 'onvo']);

        $this->sendAs('customer', 'GET', $this->onSale($this->puraNavaja))
            ->assertOk()
            ->assertJsonPath('meta.pasarela', 'onvo')
            ->assertJsonPath('meta.metodos_de_pago', ['tarjeta', 'sinpe_movil']);
    }

    /** With PayPal the list says `paypal` and only the card. */
    public function test_a_barbershop_that_collects_with_paypal_lists_paypal_and_the_card(): void
    {
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'full', 'deposit_percentage' => null, 'payment_gateway' => 'paypal']);

        $this->sendAs('customer', 'GET', $this->onSale($this->puraNavaja))
            ->assertOk()
            ->assertJsonPath('meta.pasarela', 'paypal')
            ->assertJsonPath('meta.metodos_de_pago', ['tarjeta']);
    }

    /** A barbershop that does not charge has no gateway and no methods, like a reservation that owes nothing. */
    public function test_a_barbershop_that_does_not_charge_lists_no_gateway(): void
    {
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'none', 'deposit_percentage' => null, 'payment_gateway' => null]);

        $this->sendAs('customer', 'GET', $this->onSale($this->puraNavaja))
            ->assertOk()
            ->assertJsonPath('meta.pasarela', null)
            ->assertJsonPath('meta.metodos_de_pago', []);
    }

    /** The gateway listed is the one the barbershop has at that moment: changing it changes the next reading (PA-02, RF-23). */
    public function test_the_gateway_listed_follows_the_current_configuration(): void
    {
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'full', 'deposit_percentage' => null, 'payment_gateway' => 'onvo']);
        $this->sendAs('customer', 'GET', $this->onSale($this->puraNavaja))->assertJsonPath('meta.pasarela', 'onvo');

        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_gateway' => 'paypal']);
        $this->sendAs('customer', 'GET', $this->onSale($this->puraNavaja))->assertJsonPath('meta.pasarela', 'paypal');

        // The other barbershop keeps its own: the gateway is never taken from another one (MT-03).
        $this->scopeQueriesTo($this->fadeLab);
        DB::table('barbershops')->where('id', $this->fadeLab)->update(['payment_mode' => 'full', 'deposit_percentage' => null, 'payment_gateway' => 'onvo']);
        $this->scopeQueriesTo($this->puraNavaja);
        $this->sendAs('customer', 'GET', $this->onSale($this->fadeLab))->assertJsonPath('meta.pasarela', 'onvo');
        $this->sendAs('customer', 'GET', $this->onSale($this->puraNavaja))->assertJsonPath('meta.pasarela', 'paypal');
    }

    /** A person without a role in the barbershop reads the list and is not made a customer by it (RD-18, UP-09). */
    public function test_a_person_without_a_role_reads_the_list_and_no_role_is_created(): void
    {
        $stranger = $this->insertUser('Dani Mora');
        $before = DB::table('memberships')->count();

        $this->actAs($stranger);
        $response = $this->getJson($this->onSale($this->puraNavaja))->assertOk();

        $this->assertNotSame([], $response->json('data'));
        $response->assertJsonPath('meta.bonos_activos', true);
        $this->assertSame($before, DB::table('memberships')->count());
        $this->assertSame(0, DB::table('memberships')->where('user_id', $stranger)->count());
        // The owner of Fade Lab has no role in Pura Navaja either, and gets through the same way.
        $this->sendAs('foreign_owner', 'GET', $this->onSale($this->puraNavaja))->assertOk();
        $this->assertSame($before, DB::table('memberships')->count());
    }

    /** The owner and a collaborator of the barbershop are not its customers: 403 `prohibido`; without a session, 401 (UP-07). */
    public function test_the_staff_are_refused_and_so_is_nobody(): void
    {
        $this->sendAs('anonymous', 'GET', $this->onSale($this->puraNavaja))->assertUnauthorized();

        foreach (['owner', 'collaborator', 'assigned_collaborator'] as $actor) {
            $this->sendAs($actor, 'GET', $this->onSale($this->puraNavaja))
                ->assertForbidden()
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', 'http://localhost/problemas/prohibido');
        }
    }

    /** The list of a barbershop never shows the vouchers of another, whatever the request says (MT-03). */
    public function test_the_vouchers_of_another_barbershop_are_not_listed(): void
    {
        $own = $this->resources['bono'];
        $foreign = $this->foreignResources['bono'];
        $noise = "?barberia={$this->fadeLab}&barbershop_id={$this->fadeLab}";

        $pura = $this->sendAs('customer', 'GET', $this->onSale($this->puraNavaja).$noise)->assertOk();
        $this->assertSame([$own], $pura->json('data.*.id'));
        $this->assertStringNotContainsString($foreign, (string) $pura->getContent());
        $this->assertStringNotContainsString('Corte', (string) $pura->getContent());

        $fade = $this->sendAs('customer', 'GET', $this->onSale($this->fadeLab))->assertOk();
        $this->assertSame([$foreign], $fade->json('data.*.id'));
        $this->assertSame([['id' => $this->corte, 'nombre' => 'Corte']], $fade->json('data.0.servicios'));
    }

    /** A barbershop that does not exist reads like any missing resource. */
    public function test_a_barbershop_that_does_not_exist_is_not_found(): void
    {
        $this->sendAs('customer', 'GET', $this->onSale('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0aff'))->assertNotFound();
    }

    private function onSale(string $barbershopId): string
    {
        return "/api/v1/barberias/{$barbershopId}/bonos";
    }
}
