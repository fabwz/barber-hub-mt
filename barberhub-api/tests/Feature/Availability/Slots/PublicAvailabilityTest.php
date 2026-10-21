<?php

declare(strict_types=1);

namespace Tests\Feature\Availability\Slots;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\Support\Tenancy\TenantResources;
use Tests\TestCase;

/**
 * A visitor looks for a space without a session (RF-10, RD-18). Pura Navaja opens from Monday to Saturday from 9:00 to
 * 19:00 and closes on 12 March 2030; Daniela and Kevin work there on Tuesdays. Fade Lab opens on Tuesdays and Mauricio,
 * who works from 9:00 to 19:00 with a break from 12:00 to 13:00, offers "Corte + barba". Today is Friday 1 March 2030, 8:00.
 */
final class PublicAvailabilityTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private const string TUESDAY = '2030-03-05';

    private string $puraNavaja;

    private string $fadeLab;

    private string $degradado;

    private string $daniela;

    private string $kevin;

    private string $corteYBarba;

    private string $mauricio;

    /** Build both barbershops with their hours, services and staff, and fix the clock. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Clock::class, new class implements Clock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica'));
            }
        });

        $this->puraNavaja = $this->insertBarbershop('pura-navaja');
        $this->fadeLab = $this->insertBarbershop('fade-lab');

        foreach (range(1, 6) as $day) {
            $this->insertHours($this->puraNavaja, $day);
        }
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('closures')->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $this->puraNavaja, 'closed_on' => '2030-03-12']);
        $this->degradado = $this->insertService($this->puraNavaja, 'Degradado', 45, 7000);
        $this->daniela = $this->insertCollaborator($this->puraNavaja, 'Daniela', [$this->degradado], [[2, '09:00', '19:00']], [[2, '12:00', '13:00']]);
        $this->kevin = $this->insertCollaborator($this->puraNavaja, 'Kevin', [$this->degradado], [[2, '09:00', '12:00']]);

        $this->insertHours($this->fadeLab, 2);
        $this->scopeQueriesTo($this->fadeLab);
        $this->corteYBarba = $this->insertService($this->fadeLab, 'Corte + barba', 60, 9000);
        $this->mauricio = $this->insertCollaborator($this->fadeLab, 'Mauricio', [$this->corteYBarba], [[2, '09:00', '19:00']], [[2, '12:00', '13:00']]);
    }

    /** Without a token and with a barber, the answer has the service with the VAT, the barber and the spaces with the Costa Rica offset. */
    public function test_visitor_sees_the_spaces_of_a_barber(): void
    {
        $response = $this->getJson($this->url($this->puraNavaja, $this->degradado, self::TUESDAY, $this->daniela))->assertOk();

        $response->assertJsonPath('data.servicio', ['id' => $this->degradado, 'nombre' => 'Degradado', 'duracion_minutos' => 45, 'precio' => 7000, 'base' => 6195, 'iva' => 805])
            ->assertJsonPath('data.fecha', self::TUESDAY)
            ->assertJsonPath('data.dias_siguientes', [])
            ->assertJsonCount(1, 'data.barberos')
            ->assertJsonPath('data.barberos.0.id', $this->daniela)
            ->assertJsonPath('data.barberos.0.nombre', 'Daniela')
            ->assertJsonPath('data.barberos.0.espacios.0', ['inicio' => '2030-03-05T09:00:00-06:00', 'fin' => '2030-03-05T09:45:00-06:00', 'barbero' => $this->daniela]);
        $this->assertSame(
            ['09:00', '09:15', '09:30', '09:45', '10:00', '10:15', '10:30', '10:45'],
            array_slice($this->starts($response->json('data.barberos.0.espacios')), 0, 8),
        );
        $this->assertArrayNotHasKey('imagen', $response->json('data.servicio'));
    }

    /** Without a barber the spaces come grouped by everyone who offers the service, and each space names its barber. */
    public function test_without_a_barber_the_spaces_are_grouped_by_barber(): void
    {
        $response = $this->getJson($this->url($this->puraNavaja, $this->degradado, self::TUESDAY))->assertOk()->assertJsonCount(2, 'data.barberos');

        $groups = array_column($response->json('data.barberos'), null, 'id');

        $this->assertEqualsCanonicalizing([$this->daniela, $this->kevin], array_keys($groups));
        $this->assertSame('Kevin', $groups[$this->kevin]['nombre']);
        $this->assertSame('09:00', substr($groups[$this->kevin]['espacios'][0]['inicio'], 11, 5));

        foreach ($groups as $id => $group) {
            foreach ($group['espacios'] as $space) {
                $this->assertSame($id, $space['barbero']);
            }
        }
    }

    /** The account data of the barber other than the name never leaves. */
    public function test_the_response_does_not_expose_the_email_of_the_barber(): void
    {
        $this->assertStringNotContainsString('@barberhub.test', $this->getJson($this->url($this->puraNavaja, $this->degradado, self::TUESDAY))->assertOk()->getContent() ?: '');
    }

    /** A day without spaces brings the next 3 days with spaces, skipping the closure of 12 March (HO-01). */
    public function test_a_day_without_spaces_brings_the_next_three_days_with_spaces(): void
    {
        $response = $this->getJson($this->url($this->puraNavaja, $this->degradado, '2030-03-03'))->assertOk();

        $response->assertJsonPath('data.barberos', [])
            ->assertJsonCount(3, 'data.dias_siguientes')
            ->assertJsonPath('data.dias_siguientes.0.fecha', '2030-03-05')
            ->assertJsonPath('data.dias_siguientes.1.fecha', '2030-03-19')
            ->assertJsonPath('data.dias_siguientes.2.fecha', '2030-03-26')
            ->assertJsonPath('data.dias_siguientes.2.barberos.0.espacios.0.inicio', '2030-03-26T09:00:00-06:00');
    }

    /** The two examples of the requirement: Mauricio's Tuesday starts at 9:00, has 10:45 before the break and ends at 17:45 (DI-01, DI-02). */
    public function test_the_examples_of_mauricio(): void
    {
        $starts = $this->starts($this->getJson($this->url($this->fadeLab, $this->corteYBarba, self::TUESDAY, $this->mauricio))->assertOk()->json('data.barberos.0.espacios'));

        $this->assertSame('09:00', $starts[0]);
        $this->assertSame('10:45', $starts[array_search('13:00', $starts, true) - 1]);
        $this->assertSame('17:45', end($starts));
    }

    /** A date beyond 30 days or already past answers an empty list with no next days (DI-03). */
    public function test_a_date_outside_the_window_answers_an_empty_list(): void
    {
        foreach (['2030-04-02', '2030-02-26'] as $date) {
            $this->getJson($this->url($this->puraNavaja, $this->degradado, $date))
                ->assertOk()
                ->assertJsonPath('data.barberos', [])
                ->assertJsonPath('data.dias_siguientes', []);
        }
    }

    /** A service of another barbershop, an unknown one and an inactive one answer the same 404. */
    public function test_a_service_that_cannot_be_booked_is_not_found_with_the_same_body(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        $inactive = $this->insertService($this->puraNavaja, 'Barba', 15, 3500, active: false);

        $unknown = $this->getJson($this->url($this->puraNavaja, (string) Str::uuid7(), self::TUESDAY))->assertNotFound();
        $other = $this->getJson($this->url($this->puraNavaja, $this->corteYBarba, self::TUESDAY))->assertNotFound();
        $off = $this->getJson($this->url($this->puraNavaja, $inactive, self::TUESDAY))->assertNotFound();

        $this->assertSame($this->withoutInstance($unknown->json()), $this->withoutInstance($other->json()));
        $this->assertSame($this->withoutInstance($unknown->json()), $this->withoutInstance($off->json()));
        $this->assertStringNotContainsString('Corte + barba', (string) $other->getContent());
    }

    /** A barber of another barbershop answers the 404 of an unknown one; an inactive one and one who does not offer the service say so. */
    public function test_a_barber_that_cannot_be_booked(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        $barba = $this->insertService($this->puraNavaja, 'Barba', 15, 3500);
        $withoutBarba = $this->insertCollaborator($this->puraNavaja, 'Luis', [$this->degradado], [[2, '09:00', '19:00']]);
        $inactive = $this->insertCollaborator($this->puraNavaja, 'Marta', [$this->degradado], [[2, '09:00', '19:00']], active: false);

        $unknown = $this->getJson($this->url($this->puraNavaja, $this->degradado, self::TUESDAY, (string) Str::uuid7()))->assertNotFound();
        $other = $this->getJson($this->url($this->puraNavaja, $this->degradado, self::TUESDAY, $this->mauricio))->assertNotFound();

        $this->assertSame($this->withoutInstance($unknown->json()), $this->withoutInstance($other->json()));
        $this->assertStringNotContainsString('Mauricio', (string) $other->getContent());
        $this->getJson($this->url($this->puraNavaja, $this->degradado, self::TUESDAY, $inactive))->assertUnprocessable()->assertJsonPath('status', 422);
        $this->getJson($this->url($this->puraNavaja, $barba, self::TUESDAY, $withoutBarba))->assertUnprocessable()->assertJsonPath('status', 422);
        $this->getJson($this->url($this->puraNavaja, $barba, self::TUESDAY))->assertOk()->assertJsonPath('data.barberos', []);
    }

    /** A date with a bad format, or a missing or malformed parameter, answers 422 with the field. */
    public function test_invalid_parameters_answer_422_with_the_field(): void
    {
        foreach (['2030-3-5', '05/03/2030', '2030-02-30', 'mañana'] as $date) {
            $this->getJson($this->url($this->puraNavaja, $this->degradado, $date))->assertUnprocessable()->assertJsonValidationErrors(['fecha'], 'errores');
        }

        $base = "/api/v1/barberias/{$this->puraNavaja}/disponibilidad";

        $this->getJson($base)->assertUnprocessable()->assertJsonValidationErrors(['servicio', 'fecha'], 'errores');
        $this->getJson("{$base}?servicio=abc&fecha=".self::TUESDAY)->assertUnprocessable()->assertJsonValidationErrors(['servicio'], 'errores');
        $this->getJson("{$base}?servicio={$this->degradado}&fecha=".self::TUESDAY.'&barbero=abc')->assertUnprocessable()->assertJsonValidationErrors(['barbero'], 'errores');
    }

    /** Each barbershop answers with its own data and the queries of the answer stay inside the barbershop (MT-04). */
    public function test_each_barbershop_answers_with_its_own_data(): void
    {
        $own = $this->getJson($this->url($this->fadeLab, $this->corteYBarba, self::TUESDAY))->assertOk();
        $names = array_column($own->json('data.barberos'), 'nombre');

        $this->assertSame(['Mauricio'], $names);
        $this->getJson($this->url($this->fadeLab, $this->degradado, self::TUESDAY))->assertNotFound();
    }

    /** An identifier of barbershop that is not a UUID does not reach the route, and an unknown barbershop answers the 404 of an unknown service. */
    public function test_a_malformed_or_unknown_barbershop_is_not_found(): void
    {
        $this->getJson('/api/v1/barberias/no-es-un-uuid/disponibilidad')->assertNotFound();
        $this->getJson($this->url((string) Str::uuid7(), $this->degradado, self::TUESDAY))->assertNotFound();
    }

    /** The worst case, a day-by-day search over the whole window, reads at most 7 queries per day and does not grow with the staff. */
    public function test_the_search_over_the_window_has_a_bounded_number_of_queries(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        $service = $this->insertService($this->puraNavaja, 'Tinte', 90, 20000);
        $this->insertCollaborator($this->puraNavaja, 'Sofía', [$service], [[1, '09:00', '10:00']]);

        $count = function () use ($service): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->url($this->puraNavaja, $service, '2030-03-01'))->assertOk()->assertJsonPath('data.dias_siguientes', []);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $withOne = $count();
        foreach (range(1, 3) as $unused) {
            $this->insertCollaborator($this->puraNavaja, 'Otro', [$service], [[1, '09:00', '10:00']]);
        }

        $this->assertSame($withOne, $count());
        // Fixed part: the scope, the service and the names (7). Each of the 31 days of the window reads at most the closure, the hours, the staff (4) and the reservations: 7.
        $this->assertLessThanOrEqual(7 + 31 * 7, $withOne);
    }

    /** The route is public, declared so in the registry of the tenancy suite, and takes no session. */
    public function test_route_is_public_and_registered_as_such(): void
    {
        $route = app('router')->getRoutes()->getByName('availability.slots.index');

        $this->assertNotNull($route);
        $this->assertContains('barbershop:public', $route->gatherMiddleware());
        $this->assertSame([], array_values(array_filter($route->gatherMiddleware(), fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'auth'))));
        $this->assertContains('GET api/v1/barberias/{barberia}/disponibilidad', TenantResources::PUBLIC_ROUTES);
    }

    /** Address of the availability of a barbershop. */
    private function url(string $barbershopId, string $serviceId, string $date, ?string $collaboratorId = null): string
    {
        $query = ['servicio' => $serviceId, 'fecha' => $date] + ($collaboratorId === null ? [] : ['barbero' => $collaboratorId]);

        return "/api/v1/barberias/{$barbershopId}/disponibilidad?".http_build_query($query);
    }

    /**
     * The starting hour of each space.
     *
     * @param  list<array{inicio: string}>  $spaces
     * @return list<string>
     */
    private function starts(array $spaces): array
    {
        return array_map(fn (array $space): string => substr($space['inicio'], 11, 5), $spaces);
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

    private function insertBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    private function insertHours(string $barbershopId, int $day): void
    {
        $this->scopeQueriesTo($barbershopId);
        DB::table('opening_hours')->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $barbershopId, 'day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '19:00']);
    }

    private function insertService(string $barbershopId, string $name, int $minutes, int $price, bool $active = true): string
    {
        $id = (string) Str::uuid7();
        DB::table('services')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'name' => $name, 'duration_minutes' => $minutes, 'price' => $price, 'is_active' => $active]);

        return $id;
    }

    /**
     * Insert a collaborator with the services, shifts and breaks given as [day, from, to].
     *
     * @param  list<string>  $services
     * @param  list<array{int, string, string}>  $shifts
     * @param  list<array{int, string, string}>  $breaks
     */
    private function insertCollaborator(string $barbershopId, string $name, array $services, array $shifts, array $breaks = [], bool $active = true): string
    {
        $userId = (string) Str::uuid7();
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $userId, 'name' => $name, 'email' => "{$userId}@barberhub.test", 'password' => 'hash']);
        DB::table('collaborators')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'user_id' => $userId, 'is_active' => $active]);

        foreach ($services as $service) {
            DB::table('collaborator_services')->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $barbershopId, 'collaborator_id' => $id, 'service_id' => $service]);
        }
        foreach ([['collaborator_shifts', $shifts], ['collaborator_breaks', $breaks]] as [$table, $rows]) {
            foreach ($rows as [$day, $from, $to]) {
                DB::table($table)->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $barbershopId, 'collaborator_id' => $id, 'day_of_week' => $day, 'starts_at' => $from, 'ends_at' => $to]);
            }
        }

        return $id;
    }
}
