<?php

declare(strict_types=1);

namespace Tests\Feature\Vouchers\PurchasedVouchers;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\MutableClock;
use Tests\Support\Tenancy\BuildsAccessScenario;
use Tests\TestCase;

/**
 * The customer reads their vouchers and the owner those sold in the barbershop, with balance, state and validity (RF-29, BO-07,
 * BO-09, CL-04, MT-02). Ana and Luis are customers of Pura Navaja and Bea of Fade Lab. The clock stands at 15 March 2030, 8:00;
 * the vouchers of the tests were activated on 1 March 2030, 8:00.
 */
final class ConsultPurchasedVouchersTest extends TestCase
{
    use BuildsAccessScenario;
    use RefreshDatabaseAsOwner;

    private MutableClock $clock;

    private string $definition;

    private string $foreignDefinition;

    private string $beaMembership;

    /** Both barbershops are built, and the clock stands inside the validity of the vouchers. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->buildAccessScenario();
        // The scenario fixes a clock of its own; this one goes after it, so the test can move the time.
        $this->clock = new MutableClock(new DateTimeImmutable('2030-03-15 08:00:00', new DateTimeZone('America/Costa_Rica')));
        $this->app->instance(Clock::class, $this->clock);
        $this->definition = $this->insertVoucherDefinition($this->puraNavaja, [$this->degradado]);
        $this->foreignDefinition = $this->insertVoucherDefinition($this->fadeLab, [$this->corte]);
        $this->beaMembership = (string) DB::table('memberships')->where('user_id', $this->bea)->value('id');
    }

    /** What the record adds up to is what the voucher shows, and what the table keeps as its balance (RF-29, BO-09). */
    public function test_the_balance_is_the_sum_of_the_record_and_equals_the_stored_balance(): void
    {
        $voucher = $this->insertPurchasedVoucher($this->puraNavaja, $this->anaMembership, $this->definition);
        $first = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->anaMembership, '2030-03-20T09:00:00-06:00');
        $second = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->anaMembership, '2030-03-20T11:00:00-06:00');
        // 5 bought, two set aside, one given back, one of them used: 5 - 1 - 1 + 1 + 0 = 4 sessions.
        $this->insertVoucherMovement($this->puraNavaja, $voucher, 'hold', -1, $first);
        $this->insertVoucherMovement($this->puraNavaja, $voucher, 'hold', -1, $second);
        $this->insertVoucherMovement($this->puraNavaja, $voucher, 'release', 1, $second);
        $this->insertVoucherMovement($this->puraNavaja, $voucher, 'consume', 0, $first);
        DB::table('purchased_vouchers')->where('id', $voucher)->update(['remaining_sessions' => 4]);

        $response = $this->sendAs('customer', 'GET', $this->mine($this->puraNavaja))->assertOk();

        $data = $response->json('data.0');
        $this->assertSame(4, $data['saldo']);
        $this->assertSame(4, array_sum(array_column($data['movimientos'], 'sesiones')));
        $this->assertSame((int) DB::table('purchased_vouchers')->where('id', $voucher)->value('remaining_sessions'), $data['saldo']);
        $this->assertSame(['compra', 'apartado', 'apartado', 'reintegro', 'consumo'], array_column($data['movimientos'], 'tipo'));
        $this->assertSame([null, $first, $second, $second, $first], array_column($data['movimientos'], 'reserva'));
        $response->assertJsonPath('data.0.id', $voucher)
            ->assertJsonPath('data.0.nombre', 'Bono 5 cortes')
            ->assertJsonPath('data.0.sesiones_totales', 5)
            ->assertJsonPath('data.0.estado', 'activo')
            ->assertJsonPath('data.0.activado_en', '2030-03-01T08:00:00-06:00')
            ->assertJsonPath('data.0.vence_en', '2030-05-30T08:00:00-06:00')
            ->assertJsonMissingPath('data.0.cliente_nombre');
    }

    /** A customer sees their own vouchers and never those of another person, in this barbershop or another (CL-04). */
    public function test_a_customer_sees_only_their_own_vouchers(): void
    {
        $ana = $this->insertPurchasedVoucher($this->puraNavaja, $this->anaMembership, $this->definition);
        $luis = $this->insertPurchasedVoucher($this->puraNavaja, $this->luisMembership, $this->definition, sessions: 3);
        $bea = $this->insertPurchasedVoucher($this->fadeLab, $this->beaMembership, $this->foreignDefinition);
        // Whatever the request says about someone else is ignored: the scope is the session.
        $noise = "?cliente={$this->luis}&membresia={$this->luisMembership}&barberia={$this->fadeLab}&barbershop_id={$this->fadeLab}";

        $this->assertSame([$ana], $this->sendAs('customer', 'GET', $this->mine($this->puraNavaja).$noise)->assertOk()->json('data.*.id'));
        $this->assertSame([$luis], $this->sendAs('other_customer', 'GET', $this->mine($this->puraNavaja).$noise)->assertOk()->json('data.*.id'));
        // Ana has no role in Fade Lab: she bought nothing there, and Bea's voucher is not hers.
        $this->assertSame([], $this->sendAs('customer', 'GET', $this->mine($this->fadeLab))->assertOk()->json('data'));
        $this->assertNotContains($bea, $this->sendAs('customer', 'GET', $this->mine($this->puraNavaja))->json('data.*.id'));
    }

    /** A person without a role there has nothing yet: an empty list, and nothing is created (RD-18, CL-04). */
    public function test_a_person_without_a_role_gets_an_empty_list_and_no_role_is_created(): void
    {
        $this->insertPurchasedVoucher($this->fadeLab, $this->beaMembership, $this->foreignDefinition);
        $before = DB::table('memberships')->count();

        // The owner of Fade Lab has no role in Pura Navaja: reading what is one's own there is an empty list.
        $this->sendAs('foreign_owner', 'GET', $this->mine($this->puraNavaja))->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
        $this->assertSame($before, DB::table('memberships')->count());
    }

    /** The voucher that still waits for its payment is not a voucher yet, for the customer or for the owner (BO-02). */
    public function test_a_voucher_pending_payment_is_not_shown(): void
    {
        $this->insertPurchasedVoucher($this->puraNavaja, $this->anaMembership, $this->definition, 'pending');
        $paid = $this->insertPurchasedVoucher($this->puraNavaja, $this->anaMembership, $this->definition);

        $this->assertSame([$paid], $this->sendAs('customer', 'GET', $this->mine($this->puraNavaja))->json('data.*.id'));
        $this->assertSame([$paid], $this->sendAs('owner', 'GET', $this->sold($this->puraNavaja))->json('data.*.id'));
        $this->assertSame(1, $this->sendAs('owner', 'GET', $this->sold($this->puraNavaja))->json('meta.total'));
    }

    /** The owner sees the vouchers sold in their barbershop with the name of the customer, and none of another barbershop (MT-02). */
    public function test_the_owner_sees_only_the_vouchers_sold_in_their_barbershop(): void
    {
        $ana = $this->insertPurchasedVoucher($this->puraNavaja, $this->anaMembership, $this->definition);
        $luis = $this->insertPurchasedVoucher($this->puraNavaja, $this->luisMembership, $this->definition, sessions: 3);
        $bea = $this->insertPurchasedVoucher($this->fadeLab, $this->beaMembership, $this->foreignDefinition);

        $own = $this->sendAs('owner', 'GET', $this->sold($this->puraNavaja))->assertOk();
        $this->assertEqualsCanonicalizing([$ana, $luis], $own->json('data.*.id'));
        $names = array_column($own->json('data'), 'cliente_nombre', 'id');
        $this->assertSame(['Ana Rojas', 'Luis Mora'], [$names[$ana], $names[$luis]]);
        $this->assertSame(['Bea Mora'], array_column($this->sendAs('foreign_owner', 'GET', $this->sold($this->fadeLab))->assertOk()->json('data'), 'cliente_nombre'));
        $this->assertSame([$bea], $this->sendAs('foreign_owner', 'GET', $this->sold($this->fadeLab).'?barberia='.$this->puraNavaja)->json('data.*.id'));
        // Neither the contact of the customer nor anything else of their account is given.
        $this->assertStringNotContainsString('@', (string) $own->getContent());
        $this->assertSame(['id', 'nombre', 'sesiones_totales', 'saldo', 'estado', 'activado_en', 'vence_en', 'movimientos', 'cliente_nombre'], array_keys($own->json('data.0')));
    }

    /** The owner of another barbershop, a collaborator and a customer cannot read what the owner reads (UP-03, MT-02). */
    public function test_nobody_else_reads_the_vouchers_sold(): void
    {
        $this->insertPurchasedVoucher($this->puraNavaja, $this->anaMembership, $this->definition);

        $this->sendAs('anonymous', 'GET', $this->sold($this->puraNavaja))->assertUnauthorized();
        $this->sendAs('customer', 'GET', $this->sold($this->puraNavaja))->assertForbidden();
        $this->sendAs('collaborator', 'GET', $this->sold($this->puraNavaja))->assertForbidden();
        $this->sendAs('assigned_collaborator', 'GET', $this->sold($this->puraNavaja))->assertForbidden();
        $this->sendAs('foreign_owner', 'GET', $this->sold($this->puraNavaja))->assertNotFound();
        $this->sendAs('foreign_collaborator', 'GET', $this->sold($this->puraNavaja))->assertNotFound();
    }

    /** The owner and the collaborators are not customers of their own barbershop: they get 403 on the customer list (UP-07). */
    public function test_the_staff_do_not_read_the_list_of_the_customer(): void
    {
        $this->sendAs('anonymous', 'GET', $this->mine($this->puraNavaja))->assertUnauthorized();
        $this->sendAs('owner', 'GET', $this->mine($this->puraNavaja))->assertForbidden();
        $this->sendAs('collaborator', 'GET', $this->mine($this->puraNavaja))->assertForbidden();
    }

    /** A voucher past its expiry shows no sessions, says it is expired and keeps its expiry date, with nothing stored for it (BO-07). */
    public function test_an_expired_voucher_shows_zero_balance_and_its_expiry_date(): void
    {
        $voucher = $this->insertPurchasedVoucher($this->puraNavaja, $this->anaMembership, $this->definition, validityDays: 10);
        $this->clock->moveTo(new DateTimeImmutable('2030-03-11 08:00:01', new DateTimeZone('America/Costa_Rica')));

        foreach ([['customer', $this->mine($this->puraNavaja)], ['owner', $this->sold($this->puraNavaja)]] as [$actor, $url]) {
            $this->sendAs($actor, 'GET', $url)->assertOk()
                ->assertJsonPath('data.0.id', $voucher)
                ->assertJsonPath('data.0.saldo', 0)
                ->assertJsonPath('data.0.estado', 'vencido')
                ->assertJsonPath('data.0.vence_en', '2030-03-11T08:00:00-06:00')
                ->assertJsonPath('data.0.sesiones_totales', 5);
        }

        // Nothing was changed by reading it: the sessions are lost to the reader, not erased, and no entry was written.
        $this->assertSame(5, (int) DB::table('purchased_vouchers')->where('id', $voucher)->value('remaining_sessions'));
        $this->assertSame(1, DB::table('voucher_movements')->where('purchased_voucher_id', $voucher)->count());
    }

    /** The voucher is valid up to the instant it expires and expired from it, to the second (BO-06, BO-07). */
    public function test_the_voucher_expires_exactly_at_its_expiry_instant(): void
    {
        $this->insertPurchasedVoucher($this->puraNavaja, $this->anaMembership, $this->definition, validityDays: 10);
        $zone = new DateTimeZone('America/Costa_Rica');

        $this->clock->moveTo(new DateTimeImmutable('2030-03-11 07:59:59', $zone));
        $this->sendAs('customer', 'GET', $this->mine($this->puraNavaja))->assertJsonPath('data.0.estado', 'activo')->assertJsonPath('data.0.saldo', 5);

        $this->clock->moveTo(new DateTimeImmutable('2030-03-11 08:00:00', $zone));
        $this->sendAs('customer', 'GET', $this->mine($this->puraNavaja))->assertJsonPath('data.0.estado', 'vencido')->assertJsonPath('data.0.saldo', 0);
    }

    /** A session given back after the voucher expired still shows zero: what the voucher shows then is none (BO-07). */
    public function test_an_expired_voucher_with_a_given_back_session_still_shows_zero(): void
    {
        $voucher = $this->insertPurchasedVoucher($this->puraNavaja, $this->anaMembership, $this->definition, validityDays: 10);
        $reservation = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->anaMembership, '2030-03-09T09:00:00-06:00');
        $this->insertVoucherMovement($this->puraNavaja, $voucher, 'hold', -1, $reservation);
        $this->insertVoucherMovement($this->puraNavaja, $voucher, 'release', 1, $reservation);
        $this->clock->moveTo(new DateTimeImmutable('2030-04-01 08:00:00', new DateTimeZone('America/Costa_Rica')));

        $response = $this->sendAs('customer', 'GET', $this->mine($this->puraNavaja))->assertOk()->assertJsonPath('data.0.saldo', 0)->assertJsonPath('data.0.estado', 'vencido');
        $this->assertCount(3, $response->json('data.0.movimientos'));
    }

    /** The listing is paged without repeating or skipping a voucher, the latest activated first, with the total in the meta. */
    public function test_the_listing_is_paged_and_ordered(): void
    {
        $oldest = $this->insertPurchasedVoucher($this->puraNavaja, $this->anaMembership, $this->definition);
        $middle = $this->insertPurchasedVoucher($this->puraNavaja, $this->anaMembership, $this->definition);
        $latest = $this->insertPurchasedVoucher($this->puraNavaja, $this->anaMembership, $this->definition);
        DB::table('purchased_vouchers')->where('id', $oldest)->update(['activated_at' => '2030-03-01 08:00:00-06']);
        DB::table('purchased_vouchers')->where('id', $middle)->update(['activated_at' => '2030-03-05 08:00:00-06']);
        DB::table('purchased_vouchers')->where('id', $latest)->update(['activated_at' => '2030-03-10 08:00:00-06']);

        $ids = [];

        foreach ([1, 2, 3] as $page) {
            $response = $this->sendAs('customer', 'GET', $this->mine($this->puraNavaja)."?por_pagina=1&pagina={$page}")->assertOk()
                ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.ultima_pagina', 3);
            $ids = [...$ids, ...$response->json('data.*.id')];
        }

        $this->assertSame([$latest, $middle, $oldest], $ids);
        $this->sendAs('customer', 'GET', $this->mine($this->puraNavaja).'?por_pagina=51')->assertUnprocessable();
        $this->sendAs('owner', 'GET', $this->sold($this->puraNavaja).'?por_pagina=51')->assertUnprocessable();
    }

    /** A page of vouchers reads the record and the names with a fixed number of queries, however many vouchers it has. */
    public function test_a_page_does_not_read_the_record_once_per_voucher(): void
    {
        foreach ([$this->anaMembership, $this->luisMembership, $this->anaMembership, $this->luisMembership] as $membership) {
            $this->insertPurchasedVoucher($this->puraNavaja, $membership, $this->definition);
        }

        $this->sendAs('owner', 'GET', $this->sold($this->puraNavaja))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->sendAs('owner', 'GET', $this->sold($this->puraNavaja))->assertOk()->assertJsonCount(4, 'data');
        $withFour = count(DB::getQueryLog());

        foreach ([$this->anaMembership, $this->luisMembership, $this->anaMembership, $this->luisMembership] as $membership) {
            $this->insertPurchasedVoucher($this->puraNavaja, $membership, $this->definition);
        }

        DB::flushQueryLog();
        $this->sendAs('owner', 'GET', $this->sold($this->puraNavaja))->assertOk()->assertJsonCount(8, 'data');
        $this->assertSame($withFour, count(DB::getQueryLog()));
    }

    private function mine(string $barbershopId): string
    {
        return "/api/v1/barberias/{$barbershopId}/mis-bonos";
    }

    private function sold(string $barbershopId): string
    {
        return "/api/v1/barberias/{$barbershopId}/administracion/bonos-vendidos";
    }
}
