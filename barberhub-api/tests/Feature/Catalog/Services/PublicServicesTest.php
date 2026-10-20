<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\Support\Tenancy\TenantResources;
use Tests\TestCase;

/** A visitor sees the active services of a barbershop with the price split into base and VAT (RF-03, SV-02, SV-04, RL-02). */
final class PublicServicesTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private string $puraNavaja;

    private string $fadeLab;

    /** Two barbershops, each with the services of a barbershop. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->createBarbershop('pura-navaja');
        $this->fadeLab = $this->createBarbershop('fade-lab');
    }

    /** Without a session the list brings the active services ordered by name, with only the public fields. */
    public function test_visitor_sees_the_active_services_without_a_session(): void
    {
        $corte = $this->createService($this->puraNavaja, 'Corte clásico', 30, 7000);
        $barba = $this->createService($this->puraNavaja, 'Barba', 15, 3500);

        $this->getJson($this->url($this->puraNavaja))
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $barba, 'nombre' => 'Barba', 'duracion_minutos' => 15, 'precio' => 3500, 'base' => 3097, 'iva' => 403],
                ['id' => $corte, 'nombre' => 'Corte clásico', 'duracion_minutos' => 30, 'precio' => 7000, 'base' => 6195, 'iva' => 805],
            ]]);
    }

    /** An inactive service is not offered, and it comes back when it is reactivated (SV-04). */
    public function test_inactive_services_are_left_out(): void
    {
        $this->createService($this->puraNavaja, 'Corte clásico', 30, 7000);
        $barba = $this->createService($this->puraNavaja, 'Barba', 15, 3500, active: false);

        $this->getJson($this->url($this->puraNavaja))->assertOk()->assertJsonCount(1, 'data')->assertJsonMissing(['id' => $barba]);

        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('services')->where('id', $barba)->update(['is_active' => true]);

        $this->getJson($this->url($this->puraNavaja))->assertOk()->assertJsonCount(2, 'data');
    }

    /** The base and the VAT always add up to the price, whatever the rounding (RL-02). */
    public function test_base_and_tax_add_up_to_the_price(): void
    {
        foreach ([1, 50, 113, 7500, 9999] as $price) {
            $this->createService($this->puraNavaja, "Servicio {$price}", 30, $price);
        }

        $services = $this->getJson($this->url($this->puraNavaja))->assertOk()->json('data');

        $this->assertCount(5, $services);

        foreach ($services as $service) {
            $this->assertSame($service['precio'], $service['base'] + $service['iva']);
        }
    }

    /** Each barbershop shows its own services and never those of another one (MT-04). */
    public function test_each_barbershop_shows_only_its_own_services(): void
    {
        $own = $this->createService($this->puraNavaja, 'Corte de Pura Navaja', 30, 7000);
        $other = $this->createService($this->fadeLab, 'Corte de Fade Lab', 30, 9000);

        $this->getJson($this->url($this->puraNavaja))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own);
        $this->getJson($this->url($this->fadeLab))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other);
    }

    /** A barbershop with no active services, or that does not exist, answers an empty list. */
    public function test_barbershop_without_services_or_unknown_answers_an_empty_list(): void
    {
        $this->getJson($this->url($this->puraNavaja))->assertOk()->assertExactJson(['data' => []]);
        $this->getJson($this->url((string) Str::uuid7()))->assertOk()->assertExactJson(['data' => []]);
    }

    /** An identifier that is not a UUID does not reach the route. */
    public function test_a_malformed_barbershop_is_not_found(): void
    {
        $this->getJson('/api/v1/barberias/no-es-un-uuid/servicios')->assertNotFound();
    }

    /** The route is public and declared so in the registry of the tenancy suite. */
    public function test_route_is_public_and_registered_as_such(): void
    {
        $route = app('router')->getRoutes()->getByName('catalog.services.public.index');

        $this->assertNotNull($route);
        $this->assertContains('barbershop:public', $route->gatherMiddleware());
        $this->assertSame([], array_values(array_filter($route->gatherMiddleware(), fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'auth'))));
        $this->assertContains('GET api/v1/barberias/{barberia}/servicios', TenantResources::PUBLIC_ROUTES);
    }

    /** Address of the public list of a barbershop. */
    private function url(string $barbershopId): string
    {
        return "/api/v1/barberias/{$barbershopId}/servicios";
    }

    /** Insert a barbershop straight into the table and return its identifier. */
    private function createBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** Insert a service of a barbershop straight into the table and return its identifier. */
    private function createService(string $barbershopId, string $name, int $minutes, int $price, bool $active = true): string
    {
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('services')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'name' => $name, 'duration_minutes' => $minutes, 'price' => $price, 'is_active' => $active]);

        return $id;
    }
}
