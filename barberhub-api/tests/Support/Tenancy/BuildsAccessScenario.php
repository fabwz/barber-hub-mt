<?php

declare(strict_types=1);

namespace Tests\Support\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Vouchers\SeedsVouchers;

/**
 * The two barbershops and the people of the permission tests (RF-42). Pura Navaja has its owner, the collaborators Daniela
 * and Kevin, and the customers Ana and Luis; Ana holds a reservation with Daniela and its payment. Fade Lab has its own owner,
 * the collaborator Mauricio and the customer Bea, with a reservation and a payment of their own. It builds on the barbershops
 * of the availability tests (SeedsBookableBarbershops). Requests go out with a real Sanctum token of each person.
 */
trait BuildsAccessScenario
{
    use SeedsBookableBarbershops;
    use SeedsReservations;
    use SeedsVouchers;

    /** @var array<string, string> Account of each actor of AccessMatrix::ACTORS, but the anonymous one. */
    private array $accounts = [];

    /** @var array<string, string> Token of each actor with a session. */
    private array $tokens = [];

    /** @var array<string, string> The resources of the scenario by the name of the route parameter. */
    private array $resources = [];

    /** @var array<string, string> The resources of Fade Lab by the name of the route parameter. */
    private array $foreignResources = [];

    private string $ana;

    private string $anaMembership;

    private string $luis;

    private string $luisMembership;

    private string $bea;

    /** Build both barbershops, their people and their resources, and forbid any request to a provider. */
    protected function buildAccessScenario(): void
    {
        Http::preventStrayRequests();
        $this->seedBookableBarbershops();

        $this->scopeQueriesTo($this->puraNavaja);
        [$this->ana, $this->anaMembership] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        [$this->luis, $this->luisMembership] = $this->insertCustomer($this->puraNavaja, 'Luis Mora');
        $reservation = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->anaMembership, self::TUESDAY_TEN);
        $this->resources = [
            'reserva' => $reservation,
            'pago' => $this->insertPayment($this->puraNavaja, $reservation),
            'servicio' => $this->degradado,
            'colaborador' => $this->daniela,
            'franja' => (string) DB::table('opening_hours')->value('id'),
            'cierre' => $this->insertClosure($this->puraNavaja),
            'bono' => $this->insertVoucherDefinition($this->puraNavaja, [$this->degradado]),
        ];
        $danielaAccount = (string) DB::table('collaborators')->where('id', $this->daniela)->value('user_id');
        $kevinAccount = (string) DB::table('collaborators')->where('id', $this->kevin)->value('user_id');

        $this->scopeQueriesTo($this->fadeLab);
        $foreignOwner = $this->insertUser('Dueño de Fade Lab');
        $this->grantRole($foreignOwner, $this->fadeLab, 'owner');
        [$this->bea, $beaMembership] = $this->insertCustomer($this->fadeLab, 'Bea Mora');
        $foreignReservation = $this->insertReservation($this->fadeLab, $this->mauricio, $this->corte, $beaMembership, self::TUESDAY_TEN, minutes: 30);
        $this->foreignResources = [
            'reserva' => $foreignReservation,
            'pago' => $this->insertPayment($this->fadeLab, $foreignReservation),
            'servicio' => $this->corte,
            'colaborador' => $this->mauricio,
            'franja' => (string) DB::table('opening_hours')->value('id'),
            'cierre' => $this->insertClosure($this->fadeLab),
            'bono' => $this->insertVoucherDefinition($this->fadeLab, [$this->corte]),
        ];
        $mauricioAccount = (string) DB::table('collaborators')->where('id', $this->mauricio)->value('user_id');
        $this->scopeQueriesTo(null);

        $this->accounts = [
            'customer' => $this->ana,
            'other_customer' => $this->luis,
            'collaborator' => $kevinAccount,
            'assigned_collaborator' => $danielaAccount,
            'owner' => $this->owner,
            'foreign_owner' => $foreignOwner,
            'foreign_collaborator' => $mauricioAccount,
        ];
        $this->tokens = array_map(fn (string $id): string => UserModel::query()->findOrFail($id)->createToken('prueba')->plainTextToken, $this->accounts);
    }

    /**
     * Send a request as one of the actors of the matrix; the anonymous one has no session.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function sendAs(string $actor, string $method, string $uri, array $payload = [], array $headers = []): TestResponse
    {
        // The guard remembers the person of the last request; each request here has its own.
        $this->app->make('auth')->forgetGuards();
        $this->flushHeaders();

        if (isset($this->tokens[$actor])) {
            $this->withToken($this->tokens[$actor]);
        }

        return $this->withHeaders($headers)->json($method, $uri, $payload);
    }

    /**
     * The address of a route of the matrix for a barbershop, with the resources of the scenario in its parameters.
     *
     * @param  array<string, string>  $resources
     */
    private function addressOf(string $template, string $barbershopId, array $resources): string
    {
        $uri = '/'.str_replace('{barberia}', $barbershopId, $template);

        foreach ($resources as $parameter => $id) {
            $uri = str_replace('{'.$parameter.'}', $id, $uri);
        }

        return $uri;
    }

    /**
     * The same resources with identifiers that exist nowhere.
     *
     * @return array<string, string>
     */
    private function missingResources(): array
    {
        return array_map(fn (): string => (string) Str::uuid7(), $this->resources);
    }

    /** Insert a pending payment of a reservation and return its identifier. It has no charge in the provider, so reading it asks nobody. */
    private function insertPayment(string $barbershopId, string $reservationId): string
    {
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('payments')->insert([
            'id' => $id,
            'barbershop_id' => $barbershopId,
            'reservation_id' => $reservationId,
            'provider' => 'onvo',
            'method' => 'card',
            'status' => 'pending',
            'amount' => 2100,
        ]);

        return $id;
    }

    private function insertClosure(string $barbershopId): string
    {
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('closures')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'closed_on' => '2030-04-01', 'reason' => 'Feriado']);

        return $id;
    }

    /**
     * Every row that matters of both barbershops, as each one sees it, to compare before and after a refused request.
     *
     * @return array<string, string>
     */
    private function fingerprint(): array
    {
        $fingerprint = [];

        foreach ([$this->puraNavaja, $this->fadeLab] as $barbershopId) {
            $this->scopeQueriesTo($barbershopId);

            foreach (['reservations', 'reservation_status_history', 'payments', 'services', 'collaborators', 'collaborator_services', 'collaborator_shifts', 'closures', 'opening_hours'] as $table) {
                $fingerprint["{$barbershopId} {$table}"] = md5((string) json_encode(DB::table($table)->orderBy('id')->get()));
            }
        }

        $this->scopeQueriesTo(null);

        foreach (['barbershops', 'memberships', 'users'] as $table) {
            $fingerprint[$table] = md5((string) json_encode(DB::table($table)->orderBy('id')->get()));
        }

        return $fingerprint;
    }

    /**
     * The body that lets a request reach the check of its resource: the validation of the body comes before it.
     *
     * @return array<string, mixed>
     */
    private function payloadOf(string $key): array
    {
        return match (true) {
            str_starts_with($key, 'PATCH ') && str_ends_with($key, '/reservas/{reserva}') => ['inicio' => '2030-03-05T14:00:00-06:00'],
            str_ends_with($key, '/reservas/{reserva}/pagos') => ['metodo' => 'tarjeta', 'token' => 'pm_test_1'],
            default => [],
        };
    }

    /**
     * An answer without the identifier of the request, which is different each time.
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function problemOf(TestResponse $response): array
    {
        $problem = (array) $response->json();
        unset($problem['instance']);

        return $problem;
    }
}
