<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\DB;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\TestCase;

/**
 * An assisted client exists only in the barbershop that registered them (RF-12, CL-06, MT-01 to MT-04, UP-06): with two barbershops that each have
 * one, neither is found, read, used or even named from the other, by the API or by the database.
 */
final class AssistedClientsTenancyTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;

    private string $fadeOwner;

    private string $puraClient;

    private string $fadeClient;

    /** Pura Navaja has Rodrigo and Fade Lab has Bea, each one with a reservation made by their owner. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->fadeOwner = $this->insertUser('Dueño de Fade Lab');
        $this->grantRole($this->fadeOwner, $this->fadeLab, 'owner');

        $this->actAs($this->owner);
        $this->puraClient = (string) $this->postJson($this->reservationsUrl($this->puraNavaja).'/asistida', [
            ...$this->booking(),
            'cliente_nombre' => 'Rodrigo Chaves',
            'cliente_telefono' => '8812 3456',
        ])->assertCreated()->json('data.cliente_asistido');

        $this->actAs($this->fadeOwner);
        $this->fadeClient = (string) $this->postJson($this->reservationsUrl($this->fadeLab).'/asistida', [
            'servicio' => $this->corte,
            'barbero' => $this->mauricio,
            'inicio' => self::TUESDAY_TEN,
            'cliente_nombre' => 'Bea Solano',
            'cliente_telefono' => '8999 0000',
        ])->assertCreated()->json('data.cliente_asistido');
    }

    private function searchUrl(string $barbershopId): string
    {
        return "/api/v1/barberias/{$barbershopId}/clientes-asistidos";
    }

    /** Each owner finds only the people of their own barbershop. */
    public function test_each_search_finds_only_its_own_clients(): void
    {
        $this->actAs($this->owner);
        $this->assertSame([$this->puraClient], array_column($this->getJson($this->searchUrl($this->puraNavaja))->assertOk()->json('data'), 'id'));
        $this->assertSame([], $this->getJson($this->searchUrl($this->puraNavaja).'?busqueda=Bea')->assertOk()->json('data'));

        $this->actAs($this->fadeOwner);
        $this->assertSame([$this->fadeClient], array_column($this->getJson($this->searchUrl($this->fadeLab))->assertOk()->json('data'), 'id'));
        $this->assertSame([], $this->getJson($this->searchUrl($this->fadeLab).'?busqueda=Rodrigo')->assertOk()->json('data'));
    }

    /** The owner of one barbershop cannot search the other: a person without a role there gets the 404 of a resource that does not exist (UP-06). */
    public function test_an_owner_cannot_search_the_other_barbershop(): void
    {
        $this->actAs($this->fadeOwner);

        $this->getJson($this->searchUrl($this->puraNavaja))->assertNotFound()->assertJsonPath('type', 'http://localhost/problemas/no-encontrado');
    }

    /** A client of another barbershop cannot be booked: the identifier reads like one that does not exist, and nothing is created. */
    public function test_a_client_of_the_other_barbershop_cannot_be_used(): void
    {
        $this->actAs($this->owner);
        $before = $this->countIn('reservations', $this->puraNavaja);

        $this->postJson($this->reservationsUrl($this->puraNavaja).'/asistida', [
            ...$this->booking(startsAt: '2030-03-05T14:00:00-06:00'),
            'cliente_asistido' => $this->fadeClient,
        ])->assertNotFound()->assertJsonPath('type', 'http://localhost/problemas/no-encontrado');

        $this->assertSame($before, $this->countIn('reservations', $this->puraNavaja));
    }

    /** The agenda of one barbershop never names the client of the other. */
    public function test_the_agendas_do_not_name_the_other_client(): void
    {
        $this->actAs($this->owner);
        $names = array_column($this->getJson($this->reservationsUrl($this->puraNavaja))->assertOk()->json('data'), 'cliente_nombre');
        $this->assertSame(['Rodrigo Chaves'], $names);

        $this->actAs($this->fadeOwner);
        $names = array_column($this->getJson($this->reservationsUrl($this->fadeLab))->assertOk()->json('data'), 'cliente_nombre');
        $this->assertSame(['Bea Solano'], $names);
    }

    /** The database hides each one from the other barbershop and from no barbershop at all (MT-06). */
    public function test_the_database_hides_each_client_from_the_other_barbershop(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame([$this->puraClient], DB::table('assisted_clients')->pluck('id')->all());
        $this->assertSame(0, DB::table('assisted_clients')->where('id', $this->fadeClient)->count());

        $this->scopeQueriesTo($this->fadeLab);
        $this->assertSame([$this->fadeClient], DB::table('assisted_clients')->pluck('id')->all());

        $this->scopeQueriesTo(null);
        $this->assertSame(0, DB::table('assisted_clients')->count());
    }
}
