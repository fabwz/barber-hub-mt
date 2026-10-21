<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\TestCase;

/**
 * The detail of a reservation has one address and three projections (RF-14): the owner sees the balance and the history
 * with authors, the collaborator the balance of their own reservations only, and the customer their own without either
 * (CO-04, PA-10, RE-09, UP-02, UP-06).
 */
final class ReservationDetailTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private string $ana;

    private string $luis;

    private string $outsider;

    private string $danielaUser;

    private string $kevinUser;

    private string $anaReservation;

    private string $luisReservation;

    private string $kevinReservation;

    private string $fadeLabReservation;

    /**
     * Ana has a reservation with Daniela that the owner confirmed, then cancelled; Luis one with Daniela and Kevin one of
     * Ana's. A fourth, made by the system, has no author. Fade Lab has one of its own.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->scopeQueriesTo($this->puraNavaja);
        [$this->ana, $anaHere] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        [$this->luis, $luisHere] = $this->insertCustomer($this->puraNavaja, 'Luis Mora');
        $this->outsider = $this->insertUser('Persona Nueva');
        $this->danielaUser = $this->userOf($this->daniela);
        $this->kevinUser = $this->userOf($this->kevin);

        $this->anaReservation = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $anaHere, '2030-03-05T10:00:00-06:00', 'cancelled_by_barbershop');
        $this->luisReservation = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $luisHere, '2030-03-05T11:00:00-06:00');
        $this->kevinReservation = $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $anaHere, '2030-03-06T09:00:00-06:00');

        $this->history($this->anaReservation, [
            [null, 'temporary', $this->ana, '2030-03-01 08:00:00-06'],
            ['temporary', 'confirmed', null, '2030-03-01 08:00:01-06'],
            ['confirmed', 'cancelled_by_barbershop', $this->owner, '2030-03-02 09:30:00-06'],
        ]);

        $this->scopeQueriesTo($this->fadeLab);
        $anaThere = (string) Str::uuid7();
        DB::table('memberships')->insert(['id' => $anaThere, 'user_id' => $this->ana, 'barbershop_id' => $this->fadeLab, 'role' => 'customer']);
        $this->fadeLabReservation = $this->insertReservation($this->fadeLab, $this->mauricio, $this->corte, $anaThere, '2030-03-05T10:00:00-06:00', minutes: 30);
    }

    /** The owner sees the reservation, the customer's name, the balance and the history with date and author, the system as "sistema" (RE-09). */
    public function test_the_owner_sees_the_full_detail_with_the_history(): void
    {
        $this->actAs($this->owner);

        $this->getJson($this->detailUrl($this->puraNavaja, $this->anaReservation))
            ->assertOk()
            ->assertJsonPath('data.id', $this->anaReservation)
            ->assertJsonPath('data.servicio_nombre', 'Degradado')
            ->assertJsonPath('data.barbero_nombre', 'Daniela')
            ->assertJsonPath('data.cliente_nombre', 'Ana Rojas')
            ->assertJsonPath('data.estado', 'cancelada_por_barberia')
            ->assertJsonPath('data.inicio', '2030-03-05T10:00:00-06:00')
            ->assertJsonPath('data.precio', 7000)
            ->assertJsonPath('data.plazo_cancelacion_horas', 24)
            ->assertJsonPath('data.saldo_pendiente', 7000)
            ->assertJsonPath('data.historial', [
                ['de' => null, 'a' => 'temporal', 'autor' => 'Ana Rojas', 'fecha' => '2030-03-01T08:00:00-06:00'],
                ['de' => 'temporal', 'a' => 'confirmada', 'autor' => 'sistema', 'fecha' => '2030-03-01T08:00:01-06:00'],
                ['de' => 'confirmada', 'a' => 'cancelada_por_barberia', 'autor' => 'Mauricio Dueño', 'fecha' => '2030-03-02T09:30:00-06:00'],
            ]);
    }

    /** The owner sees any reservation of the barbershop, whichever collaborator holds it; one without history has an empty list. */
    public function test_the_owner_sees_any_reservation_of_the_barbershop(): void
    {
        $this->actAs($this->owner);

        $this->getJson($this->detailUrl($this->puraNavaja, $this->kevinReservation))->assertOk()->assertJsonPath('data.barbero_nombre', 'Kevin')->assertJsonPath('data.historial', []);
        $this->getJson($this->detailUrl($this->puraNavaja, $this->luisReservation))->assertOk()->assertJsonPath('data.cliente_nombre', 'Luis Mora');
    }

    /** The history and its authors cost the same few reads whatever the number of entries: no read per entry. */
    public function test_the_history_does_not_read_row_by_row(): void
    {
        $this->actAs($this->owner);
        $this->history($this->luisReservation, [[null, 'temporary', $this->ana, '2030-03-01 08:00:00-06']]);
        $few = $this->queriesOf($this->detailUrl($this->puraNavaja, $this->luisReservation));

        $this->history($this->luisReservation, array_map(fn (int $i): array => ['temporary', 'confirmed', $i % 2 === 0 ? $this->ana : $this->luis, "2030-03-02 09:0{$i}:00-06"], range(0, 8)));
        $this->getJson($this->detailUrl($this->puraNavaja, $this->luisReservation))->assertOk()->assertJsonCount(10, 'data.historial');
        $many = $this->queriesOf($this->detailUrl($this->puraNavaja, $this->luisReservation));

        $this->assertSame(count($few), count($many));
        $this->assertCount(1, array_filter($many, fn (string $sql): bool => str_contains($sql, 'from "reservation_status_history"')));
    }

    /** A collaborator sees the balance of their own reservation and neither the history, nor payments, nor income (CO-04, PA-10). */
    public function test_a_collaborator_sees_the_balance_but_not_the_history(): void
    {
        $this->actAs($this->danielaUser);

        $response = $this->getJson($this->detailUrl($this->puraNavaja, $this->anaReservation))
            ->assertOk()
            ->assertJsonPath('data.cliente_nombre', 'Ana Rojas')
            ->assertJsonPath('data.saldo_pendiente', 7000)
            ->assertJsonMissingPath('data.historial');

        $this->assertStringNotContainsString('Mauricio Dueño', (string) $response->getContent());
        $this->assertStringNotContainsString('@barberhub.test', (string) $response->getContent());
    }

    /** A collaborator asking for a reservation of another collaborator gets the 404 of a reservation that does not exist (UP-06). */
    public function test_a_collaborator_does_not_see_the_reservation_of_another(): void
    {
        $this->actAs($this->kevinUser);

        $foreign = $this->getJson($this->detailUrl($this->puraNavaja, $this->anaReservation))->assertNotFound();
        $missing = $this->getJson($this->detailUrl($this->puraNavaja, (string) Str::uuid7()))->assertNotFound();

        $this->assertSame($this->withoutInstance($missing->json()), $this->withoutInstance($foreign->json()));
        $this->getJson($this->detailUrl($this->puraNavaja, $this->kevinReservation))->assertOk();
    }

    /** A customer sees their reservation without the balance, the history or the authors, and without the name of anyone (RF-14, PA-10). */
    public function test_a_customer_sees_their_own_without_the_history(): void
    {
        $this->actAs($this->ana);

        $response = $this->getJson($this->detailUrl($this->puraNavaja, $this->anaReservation))
            ->assertOk()
            ->assertJsonPath('data.id', $this->anaReservation)
            ->assertJsonPath('data.servicio_nombre', 'Degradado')
            ->assertJsonPath('data.barbero_nombre', 'Daniela')
            ->assertJsonPath('data.estado', 'cancelada_por_barberia')
            ->assertJsonPath('data.precio', 7000)
            ->assertJsonPath('data.plazo_cancelacion_horas', 24)
            ->assertJsonMissingPath('data.historial')
            ->assertJsonMissingPath('data.saldo_pendiente')
            ->assertJsonMissingPath('data.cliente_nombre');

        $this->assertStringNotContainsString('Mauricio Dueño', (string) $response->getContent());
    }

    /** A customer asking for another customer's reservation, a missing one or one of another barbershop gets the same 404 (UP-02, UP-06). */
    public function test_a_customer_does_not_see_foreign_reservations(): void
    {
        $this->actAs($this->luis);

        $missing = $this->getJson($this->detailUrl($this->puraNavaja, (string) Str::uuid7()))->assertNotFound();

        foreach ([$this->anaReservation, $this->fadeLabReservation] as $foreign) {
            $response = $this->getJson($this->detailUrl($this->puraNavaja, $foreign))->assertNotFound();
            $this->assertSame($this->withoutInstance($missing->json()), $this->withoutInstance($response->json()));
        }
    }

    /** The owner, a collaborator and a customer of Pura Navaja cannot reach a reservation of Fade Lab, as if it did not exist. */
    public function test_a_reservation_of_another_barbershop_is_a_404_for_every_role(): void
    {
        $missing = null;

        foreach ([$this->owner, $this->danielaUser, $this->ana] as $person) {
            $this->actAs($person);
            $response = $this->getJson($this->detailUrl($this->puraNavaja, $this->fadeLabReservation))->assertNotFound();
            $missing ??= $this->withoutInstance($response->json());
            $this->assertSame($missing, $this->withoutInstance($response->json()));
        }
    }

    /** A person without a role in the barbershop finds nothing, and asking creates no role (UP-06). */
    public function test_a_person_without_a_role_gets_a_404(): void
    {
        $this->actAs($this->outsider);

        $this->getJson($this->detailUrl($this->puraNavaja, $this->anaReservation))->assertNotFound();
        $this->assertSame(0, DB::table('memberships')->where('user_id', $this->outsider)->count());
    }

    /** Without a session the detail answers 401. */
    public function test_without_a_session_the_detail_answers_401(): void
    {
        $this->getJson($this->detailUrl($this->puraNavaja, $this->anaReservation))->assertUnauthorized();
    }

    /** An identifier that is not a UUID is as missing as an unknown one. */
    public function test_an_identifier_that_is_not_a_uuid_is_a_404(): void
    {
        $this->actAs($this->owner);

        $this->getJson($this->detailUrl($this->puraNavaja, 'no-es-un-uuid'))->assertNotFound();
    }

    /**
     * The statements a request runs.
     *
     * @return list<string>
     */
    private function queriesOf(string $url): array
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->getJson($url)->assertOk();

        return $queries;
    }

    private function detailUrl(string $barbershopId, string $reservationId): string
    {
        return $this->reservationsUrl($barbershopId)."/{$reservationId}";
    }

    /**
     * Write entries of the history of a reservation of Pura Navaja.
     *
     * @param  list<array{string|null, string, string|null, string}>  $entries  from, to, author and moment
     */
    private function history(string $reservationId, array $entries): void
    {
        $this->scopeQueriesTo($this->puraNavaja);

        foreach ($entries as [$from, $to, $author, $at]) {
            DB::table('reservation_status_history')->insert([
                'id' => (string) Str::uuid7(),
                'barbershop_id' => $this->puraNavaja,
                'reservation_id' => $reservationId,
                'from_status' => $from,
                'to_status' => $to,
                'changed_by_user_id' => $author,
                'changed_at' => $at,
            ]);
        }
    }

    /** The account behind a collaborator profile. */
    private function userOf(string $collaboratorId): string
    {
        foreach ([$this->puraNavaja, $this->fadeLab] as $barbershopId) {
            $this->scopeQueriesTo($barbershopId);
            $userId = DB::table('collaborators')->where('id', $collaboratorId)->value('user_id');

            if (is_string($userId)) {
                return $userId;
            }
        }

        throw new \LogicException("No account for the collaborator {$collaboratorId}.");
    }
}
