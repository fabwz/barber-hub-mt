<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use Illuminate\Support\Facades\DB;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\TestCase;

/**
 * "Any available" (RF-10, RF-11, DI-04): the availability shows one space per hour with the barber assigned, and the booking
 * without a barber assigns the free one with the fewest reservations that day, the oldest registration on a tie. Daniela
 * (registered first) and Kevin offer "Degradado" on Tuesdays; Kevin only until 12:00. Today is Friday 1 March 2030, 8:00.
 */
final class AnyAvailableReservationTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private string $ana;

    private string $customer;

    /** Seed the barbershops, Ana without a role and a customer who holds the reservations the tests insert. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->ana = $this->insertUser('Ana Rojas');
        [, $this->customer] = $this->insertCustomer($this->puraNavaja, 'Luis Mora');
    }

    /** With the same reservations that day the one registered first, Daniela, is assigned; the answer names her (DI-04). */
    public function test_a_tie_assigns_the_oldest_registration(): void
    {
        $this->actAs($this->ana);

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->anyBooking())
            ->assertCreated()
            ->assertJsonPath('data.barbero', $this->daniela);
    }

    /** Daniela already has a reservation that day, so Kevin, with none, is assigned, though she is older (DI-04). */
    public function test_the_one_with_fewer_reservations_that_day_is_assigned(): void
    {
        $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->customer, '2030-03-05T15:00:00-06:00');
        $this->actAs($this->ana);

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->anyBooking())
            ->assertCreated()
            ->assertJsonPath('data.barbero', $this->kevin);
    }

    /** Completed, no-show and temporary reservations of the day weigh on it, and the way they were paid does not matter (DI-04). */
    public function test_completed_no_show_and_temporary_reservations_count(): void
    {
        $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->customer, '2030-03-05T09:00:00-06:00', 'completed');
        $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->customer, '2030-03-05T15:00:00-06:00', 'no_show');
        $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $this->customer, '2030-03-05T09:00:00-06:00', 'cancelled_by_customer');
        $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $this->customer, '2030-03-05T11:00:00-06:00', 'expired');
        $this->actAs($this->ana);

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->anyBooking())
            ->assertCreated()
            ->assertJsonPath('data.barbero', $this->kevin);
    }

    /** Cancelled and expired reservations do not count: with only those, the tie goes back to the oldest registration (DI-04). */
    public function test_cancelled_and_expired_reservations_do_not_count(): void
    {
        $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $this->customer, '2030-03-05T09:00:00-06:00', 'cancelled_by_barbershop');
        $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $this->customer, '2030-03-05T11:00:00-06:00', 'expired');
        $this->actAs($this->ana);

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->anyBooking())
            ->assertCreated()
            ->assertJsonPath('data.barbero', $this->daniela);
    }

    /** The reservations of another day do not count: "that day" is the day of the appointment (DI-04). */
    public function test_reservations_of_other_days_do_not_count(): void
    {
        $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->customer, '2030-03-12T15:00:00-06:00');
        $this->actAs($this->ana);

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->anyBooking())
            ->assertCreated()
            ->assertJsonPath('data.barbero', $this->daniela);
    }

    /** Only who is free takes it: Daniela is busy at 10:00, so Kevin does, whatever the counts. */
    public function test_only_a_free_collaborator_is_assigned(): void
    {
        $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->customer, self::TUESDAY_TEN);
        [, $other] = $this->insertCustomer($this->puraNavaja, 'Marta Solís');
        $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $other, '2030-03-05T09:00:00-06:00');
        $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $other, '2030-03-05T11:00:00-06:00');
        $this->actAs($this->ana);

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->anyBooking())
            ->assertCreated()
            ->assertJsonPath('data.barbero', $this->kevin);
    }

    /** If nobody is free: 409 `espacio-no-disponible` with up to 3 alternatives of any barber, the closest to the time asked (RE-02). */
    public function test_nobody_free_answers_with_alternatives_of_any_barber(): void
    {
        [, $other] = $this->insertCustomer($this->puraNavaja, 'Marta Solís');
        $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->customer, self::TUESDAY_TEN);
        $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $other, self::TUESDAY_TEN);
        $this->actAs($this->ana);

        $response = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->anyBooking())
            ->assertConflict()
            ->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, 'espacio-no-disponible'));

        $alternatives = $response->json('alternativas');
        $this->assertCount(3, $alternatives);
        $this->assertContains($alternatives[0]['barbero'], [$this->daniela, $this->kevin]);
        $this->assertSame(0, DB::table('memberships')->where('user_id', $this->ana)->count(), 'The refusal leaves no customer role (UP-09).');
        $this->assertSame(2, $this->countIn('reservations', $this->puraNavaja));
    }

    /** A barber named keeps working as always: Kevin is booked even though Daniela would be assigned. */
    public function test_a_named_barber_is_not_reassigned(): void
    {
        $this->actAs($this->ana);

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(collaboratorId: $this->kevin))
            ->assertCreated()
            ->assertJsonPath('data.barbero', $this->kevin);
    }

    /** A null `barbero` is "any available" as much as an omitted one. */
    public function test_a_null_barber_is_any_available(): void
    {
        $this->actAs($this->ana);

        $this->postJson($this->reservationsUrl($this->puraNavaja), [...$this->anyBooking(), 'barbero' => null])
            ->assertCreated()
            ->assertJsonPath('data.barbero', $this->daniela);
    }

    /** A `barbero` that is not an identifier is still refused. */
    public function test_a_malformed_barber_is_refused(): void
    {
        $this->actAs($this->ana);

        $this->postJson($this->reservationsUrl($this->puraNavaja), [...$this->anyBooking(), 'barbero' => 'cualquiera'])
            ->assertUnprocessable()
            ->assertJsonStructure(['errores' => ['barbero']]);
    }

    /** The availability with `cualquiera` shows one space per start, each with its assigned barber, grouped under that barber (DI-04). */
    public function test_the_availability_shows_one_space_per_start_with_the_assigned_barber(): void
    {
        $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->customer, '2030-03-05T15:00:00-06:00');

        $response = $this->getJson($this->availabilityUrl(['cualquiera' => 'true']))->assertOk();

        $spaces = [];
        foreach ($response->json('data.barberos') as $group) {
            foreach ($group['espacios'] as $space) {
                $this->assertSame($group['id'], $space['barbero']);
                $spaces[$space['inicio']] = $space['barbero'];
            }
        }

        $this->assertSame(count($spaces), count(array_merge(...array_map(fn (array $group): array => $group['espacios'], $response->json('data.barberos')))), 'No start may appear twice.');
        $this->assertSame($this->kevin, $spaces['2030-03-05T10:00:00-06:00'], 'Daniela has one reservation that day and Kevin none.');
        $this->assertSame($this->daniela, $spaces['2030-03-05T13:00:00-06:00'], 'After noon only Daniela works.');
    }

    /** Without `cualquiera` the availability is the one of always: both barbers have the same starts. */
    public function test_without_the_parameter_the_availability_does_not_change(): void
    {
        $response = $this->getJson($this->availabilityUrl([]))->assertOk();

        $this->assertCount(2, $response->json('data.barberos'));
        $this->assertSame(
            array_column($response->json('data.barberos.0.espacios'), 'inicio')[0],
            array_column($response->json('data.barberos.1.espacios'), 'inicio')[0],
        );
    }

    /** `cualquiera` and `barbero` do not go together. */
    public function test_any_and_a_named_barber_are_incompatible(): void
    {
        $this->getJson($this->availabilityUrl(['cualquiera' => 'true', 'barbero' => $this->kevin]))
            ->assertUnprocessable()
            ->assertJsonStructure(['errores' => ['barbero']]);
    }

    /** `cualquiera` false is the same as leaving it out, even with a barber. */
    public function test_any_false_is_the_same_as_leaving_it_out(): void
    {
        $this->getJson($this->availabilityUrl(['cualquiera' => 'false', 'barbero' => $this->kevin]))
            ->assertOk()
            ->assertJsonCount(1, 'data.barberos');
    }

    /** The assignment shown is the one the booking makes, with the same counts. */
    public function test_the_barber_shown_is_the_one_the_booking_assigns(): void
    {
        $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->customer, '2030-03-05T15:00:00-06:00');
        $shown = [];
        foreach ($this->getJson($this->availabilityUrl(['cualquiera' => 'true']))->json('data.barberos') as $group) {
            foreach ($group['espacios'] as $space) {
                $shown[$space['inicio']] = $space['barbero'];
            }
        }
        $this->actAs($this->ana);

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->anyBooking())
            ->assertCreated()
            ->assertJsonPath('data.barbero', $shown['2030-03-05T10:00:00-06:00']);
    }

    /**
     * The body of a booking without a barber.
     *
     * @return array{servicio: string, inicio: string}
     */
    private function anyBooking(): array
    {
        return ['servicio' => $this->degradado, 'inicio' => self::TUESDAY_TEN];
    }

    /** @param array<string, string> $query */
    private function availabilityUrl(array $query): string
    {
        return "/api/v1/barberias/{$this->puraNavaja}/disponibilidad?".http_build_query(['servicio' => $this->degradado, 'fecha' => '2030-03-05', ...$query]);
    }
}
