<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Src\Reservations\Reservations\Application\Contracts\ReservationExtractor;
use Src\Reservations\Reservations\Application\DTOs\ExtractedDayPart;
use Src\Reservations\Reservations\Application\DTOs\ExtractedReservation;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\AiTestCase;
use Tests\Support\Reservations\FakeReservationExtractor;
use Tests\Support\Reservations\SeedsBookableBarbershops;

/**
 * The proposal is checked against the real catalog and availability of the barbershop before anyone uses it (RF-37, IA-02, DI-01):
 * the provider is simulated with a fake extractor, so no test calls Gemini. Today is Friday 1 March 2030; Pura Navaja opens from
 * Monday to Saturday from 9:00 to 19:00, Daniela works on Tuesdays from 9:00 to 19:00 with a break from 12:00 to 13:00 and Kevin from
 * 9:00 to 12:00, and the first Tuesday after today is 5 March.
 */
final class ValidateProposalTest extends AiTestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;

    private const string TUESDAY = '2030-03-05';

    private string $ana;

    private string $corteClasico;

    private string $corteYBarba;

    /** Seed the barbershops, two more cuts that Daniela offers, and an account without any role: Ana. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->scopeQueriesTo($this->puraNavaja);
        $this->corteClasico = $this->insertService($this->puraNavaja, 'Corte clásico', 30, 5000);
        $this->corteYBarba = $this->insertService($this->puraNavaja, 'Corte y barba', 60, 9000);
        $this->offer($this->daniela, $this->corteClasico);
        $this->offer($this->daniela, $this->corteYBarba);
        $this->ana = $this->insertUser('Ana Rojas');
        $this->actAs($this->ana);
    }

    /** A service, a barber, a day and a free time give the identifiers, the start, the end and the price of OUR catalog (RF-37). */
    public function test_a_valid_proposal_carries_the_ids_the_start_and_the_price_of_the_catalog(): void
    {
        $this->extract(new ExtractedReservation('degradado', 'Daniela', self::TUESDAY, '10:00'));

        $this->propose()->assertOk()->assertJson(['data' => [
            'estado' => 'valida',
            'validada' => true,
            'campo' => null,
            'aclaracion' => null,
            'servicio' => 'degradado',
            'barbero' => 'Daniela',
            'servicio_id' => $this->degradado,
            'barbero_id' => $this->daniela,
            'inicio' => '2030-03-05T10:00:00-06:00',
            'fin' => '2030-03-05T10:45:00-06:00',
            'precio' => 7000,
            'candidatos' => [],
            'espacios' => [],
            'alternativas' => [],
        ]]);
    }

    /** What the proposal says can be booked as it is: the same start and the same identifiers book with the normal endpoint. */
    public function test_the_valid_proposal_can_be_booked_with_the_normal_endpoint(): void
    {
        $this->extract(new ExtractedReservation('degradado', 'Daniela', self::TUESDAY, '10:00'));
        $data = $this->propose()->assertOk()->json('data');

        $this->postJson($this->reservationsUrl($this->puraNavaja), ['servicio' => $data['servicio_id'], 'barbero' => $data['barbero_id'], 'inicio' => $data['inicio']])
            ->assertCreated();
    }

    /** The comparison ignores case, accents and signs, and an exact name wins. */
    public function test_a_service_matches_its_exact_name_without_case_accents_or_signs(): void
    {
        $this->extract(new ExtractedReservation('  CORTE   Clasico!! ', 'daniela', self::TUESDAY, '10:00'));

        $this->propose()->assertOk()
            ->assertJsonPath('data.estado', 'valida')
            ->assertJsonPath('data.servicio_id', $this->corteClasico)
            ->assertJsonPath('data.precio', 5000);
    }

    /** A text contained in exactly one name resolves to it. */
    public function test_a_service_matches_a_text_contained_in_one_name(): void
    {
        $this->extract(new ExtractedReservation('barba', 'Daniela', self::TUESDAY, '10:00'));

        $this->propose()->assertOk()
            ->assertJsonPath('data.estado', 'valida')
            ->assertJsonPath('data.servicio_id', $this->corteYBarba)
            ->assertJsonPath('data.precio', 9000)
            ->assertJsonPath('data.fin', '2030-03-05T11:00:00-06:00');
    }

    /** "corte" fits two cuts: the proposal is ambiguous, lists our candidates and picks none. */
    public function test_an_ambiguous_service_lists_the_candidates_and_picks_none(): void
    {
        $this->extract(new ExtractedReservation('corte', 'Daniela', self::TUESDAY, '10:00'));

        $response = $this->propose()->assertOk()
            ->assertJsonPath('data.estado', 'ambigua')
            ->assertJsonPath('data.campo', 'servicio')
            ->assertJsonPath('data.validada', false)
            ->assertJsonPath('data.servicio_id', null)
            ->assertJsonPath('data.barbero_id', null)
            ->assertJsonPath('data.inicio', null)
            ->assertJsonPath('data.precio', null);

        $this->assertEqualsCanonicalizing(
            [['id' => $this->corteClasico, 'nombre' => 'Corte clásico'], ['id' => $this->corteYBarba, 'nombre' => 'Corte y barba']],
            $response->json('data.candidatos'),
        );
    }

    /** A service that is not in the catalog is marked as not recognized and nothing else is proposed in its place. */
    public function test_an_unknown_service_is_not_recognized(): void
    {
        $this->extract(new ExtractedReservation('masaje de espalda', 'Daniela', self::TUESDAY, '10:00'));

        $this->propose()->assertOk()
            ->assertJsonPath('data.estado', 'no_reconocida')
            ->assertJsonPath('data.campo', 'servicio')
            ->assertJsonPath('data.validada', false)
            ->assertJsonPath('data.servicio_id', null)
            ->assertJsonPath('data.candidatos', [])
            ->assertJsonPath('data.espacios', [])
            ->assertJsonPath('data.alternativas', []);
    }

    /** An unknown barber is not recognized, the service stays resolved and no other barber is put in their place. */
    public function test_an_unknown_barber_is_not_recognized(): void
    {
        $this->extract(new ExtractedReservation('degradado', 'Zacarías', self::TUESDAY, '10:00'));

        $this->propose()->assertOk()
            ->assertJsonPath('data.estado', 'no_reconocida')
            ->assertJsonPath('data.campo', 'barbero')
            ->assertJsonPath('data.servicio_id', $this->degradado)
            ->assertJsonPath('data.barbero_id', null)
            ->assertJsonPath('data.inicio', null);
    }

    /** A service of another barbershop is never proposed, not even by the identifier it has there (the "Corte" of Fade Lab is not among the candidates of "corte" here). */
    public function test_a_service_of_another_barbershop_is_never_proposed(): void
    {
        $this->extract(new ExtractedReservation($this->corte, null, self::TUESDAY, '10:00'));

        $this->propose()->assertOk()
            ->assertJsonPath('data.estado', 'no_reconocida')
            ->assertJsonPath('data.servicio_id', null);
    }

    /** A barber of another barbershop is never proposed. */
    public function test_a_barber_of_another_barbershop_is_never_proposed(): void
    {
        $this->extract(new ExtractedReservation('degradado', 'Mauricio', self::TUESDAY, '10:00'));

        $this->propose()->assertOk()
            ->assertJsonPath('data.estado', 'no_reconocida')
            ->assertJsonPath('data.campo', 'barbero')
            ->assertJsonPath('data.barbero_id', null);
    }

    /** The same phrase in the other barbershop resolves there, with its own identifiers and price. */
    public function test_each_barbershop_resolves_against_its_own_catalog(): void
    {
        $this->extract(new ExtractedReservation('corte', 'Mauricio', self::TUESDAY, '10:00'));

        $this->propose($this->fadeLab)->assertOk()
            ->assertJsonPath('data.estado', 'valida')
            ->assertJsonPath('data.servicio_id', $this->corte)
            ->assertJsonPath('data.barbero_id', $this->mauricio)
            ->assertJsonPath('data.precio', 5000);

        $this->propose($this->puraNavaja)->assertOk()->assertJsonPath('data.estado', 'ambigua')->assertJsonPath('data.campo', 'servicio');

        $this->extract(new ExtractedReservation('degradado', 'Daniela', self::TUESDAY, '10:00'));
        $this->propose($this->fadeLab)->assertOk()->assertJsonPath('data.estado', 'no_reconocida')->assertJsonPath('data.servicio_id', null);
    }

    /** An inactive service is never proposed. */
    public function test_an_inactive_service_is_never_proposed(): void
    {
        $tinte = $this->insertService($this->puraNavaja, 'Tinte', 30, 9000, active: false);
        $this->offer($this->daniela, $tinte);
        $this->extract(new ExtractedReservation('tinte', 'Daniela', self::TUESDAY, '10:00'));

        $this->propose()->assertOk()->assertJsonPath('data.estado', 'no_reconocida')->assertJsonPath('data.servicio_id', null);
    }

    /** An inactive barber is never proposed, by name or among the free ones. */
    public function test_an_inactive_barber_is_never_proposed(): void
    {
        DB::table('collaborators')->where('id', $this->kevin)->update(['is_active' => false]);

        $this->extract(new ExtractedReservation('degradado', 'Kevin', self::TUESDAY, '10:00'));
        $this->propose()->assertOk()->assertJsonPath('data.estado', 'no_reconocida')->assertJsonPath('data.campo', 'barbero');

        $this->extract(new ExtractedReservation('degradado', null, self::TUESDAY, '10:00'));
        $response = $this->propose()->assertOk()->assertJsonPath('data.estado', 'ambigua');
        $this->assertSame([['id' => $this->daniela, 'nombre' => 'Daniela']], $response->json('data.candidatos'));
    }

    /** A barber who does not offer the service is not recognized for it, even if they are active. */
    public function test_a_barber_who_does_not_offer_the_service_is_not_recognized(): void
    {
        $this->extract(new ExtractedReservation('corte y barba', 'Kevin', self::TUESDAY, '10:00'));

        $this->propose()->assertOk()
            ->assertJsonPath('data.estado', 'no_reconocida')
            ->assertJsonPath('data.campo', 'barbero')
            ->assertJsonPath('data.servicio_id', $this->corteYBarba);
    }

    /** A taken space is not available and offers the same alternatives the booking gives with its 409 (RE-02). */
    public function test_a_taken_space_offers_the_alternatives_of_the_booking(): void
    {
        $luis = $this->insertUser('Luis Mora');
        $this->actAs($luis);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-03-05T10:00:00-06:00'))->assertCreated();
        $this->actAs($this->ana);

        $conflict = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-03-05T10:00:00-06:00'))->assertStatus(409);
        $this->extract(new ExtractedReservation('degradado', 'Daniela', self::TUESDAY, '10:00'));

        $response = $this->propose()->assertOk()
            ->assertJsonPath('data.estado', 'no_disponible')
            ->assertJsonPath('data.campo', 'hora')
            ->assertJsonPath('data.validada', false)
            ->assertJsonPath('data.inicio', null);

        $this->assertCount(3, $response->json('data.alternativas'));
        $this->assertSame($conflict->json('alternativas'), $response->json('data.alternativas'));
        $this->assertNotContains('2030-03-05T10:00:00-06:00', array_column($response->json('data.alternativas'), 'inicio'));
    }

    /** A time out of the opening hours or inside the break of the barber is not available (DI-01). */
    public function test_a_time_outside_the_hours_or_in_the_break_is_not_available(): void
    {
        foreach (['08:00', '12:00', '20:00'] as $time) {
            $this->extract(new ExtractedReservation('degradado', 'Daniela', self::TUESDAY, $time));

            $this->propose()->assertOk()
                ->assertJsonPath('data.estado', 'no_disponible')
                ->assertJsonPath('data.campo', 'hora')
                ->assertJsonPath('data.inicio', null);
        }

        $this->extract(new ExtractedReservation('degradado', 'Daniela', self::TUESDAY, '08:00'));
        $this->assertSame('2030-03-05T09:00:00-06:00', $this->propose()->json('data.alternativas.0.inicio'));
    }

    /** A day the barbershop is closed has no spaces (DI-01). */
    public function test_a_closed_day_is_not_available(): void
    {
        DB::table('closures')->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $this->puraNavaja, 'closed_on' => '2030-03-12']);
        $this->extract(new ExtractedReservation('degradado', 'Daniela', '2030-03-12', '10:00'));

        $this->propose()->assertOk()
            ->assertJsonPath('data.estado', 'no_disponible')
            ->assertJsonPath('data.campo', 'hora')
            ->assertJsonPath('data.alternativas', []);
    }

    /** A day beyond the booking window of 30 days has no spaces (DI-03). */
    public function test_a_day_beyond_the_booking_window_is_not_available(): void
    {
        $this->extract(new ExtractedReservation('degradado', 'Daniela', '2030-06-04', '10:00'));

        $this->propose()->assertOk()->assertJsonPath('data.estado', 'no_disponible');
    }

    /** Without a barber it never picks one: the free ones are offered, with the real spaces, and no barber is set. */
    public function test_without_a_barber_the_free_ones_are_offered_and_none_is_picked(): void
    {
        $this->extract(new ExtractedReservation('degradado', null, self::TUESDAY, '10:00'));

        $response = $this->propose()->assertOk()
            ->assertJsonPath('data.estado', 'ambigua')
            ->assertJsonPath('data.campo', 'barbero')
            ->assertJsonPath('data.validada', false)
            ->assertJsonPath('data.barbero_id', null)
            ->assertJsonPath('data.inicio', null)
            ->assertJsonPath('data.servicio_id', $this->degradado)
            ->assertJsonPath('data.precio', 7000);

        $this->assertEqualsCanonicalizing(
            [['id' => $this->daniela, 'nombre' => 'Daniela'], ['id' => $this->kevin, 'nombre' => 'Kevin']],
            $response->json('data.candidatos'),
        );
        $this->assertEqualsCanonicalizing([$this->daniela, $this->kevin], array_column($response->json('data.espacios'), 'barbero'));
    }

    /** With a single barber free the proposal still asks the person: the choice is never made for them. */
    public function test_a_single_free_barber_is_still_offered_and_not_picked(): void
    {
        $this->extract(new ExtractedReservation('degradado', null, self::TUESDAY, '15:00'));

        $response = $this->propose()->assertOk()
            ->assertJsonPath('data.estado', 'ambigua')
            ->assertJsonPath('data.barbero_id', null);

        $this->assertSame([['id' => $this->daniela, 'nombre' => 'Daniela']], $response->json('data.candidatos'));
    }

    /** Without a barber and with nobody free, the closest alternatives of all the barbers are offered. */
    public function test_without_a_barber_and_nobody_free_the_closest_alternatives_are_offered(): void
    {
        $this->extract(new ExtractedReservation('degradado', null, self::TUESDAY, '12:30'));

        $response = $this->propose()->assertOk()->assertJsonPath('data.estado', 'no_disponible')->assertJsonPath('data.campo', 'hora');

        $this->assertCount(3, $response->json('data.alternativas'));
    }

    /** A part of the day lists the real free spaces inside it of the barber (RF-37), and sets no start yet. */
    public function test_a_part_of_the_day_lists_the_real_spaces_of_the_barber(): void
    {
        $this->extract(new ExtractedReservation('degradado', 'Daniela', self::TUESDAY, dayPart: ExtractedDayPart::Afternoon));

        $response = $this->propose()->assertOk()
            ->assertJsonPath('data.estado', 'valida')
            ->assertJsonPath('data.barbero_id', $this->daniela)
            ->assertJsonPath('data.inicio', null)
            ->assertJsonPath('data.fin', null);

        $starts = array_column($response->json('data.espacios'), 'inicio');
        $this->assertSame('2030-03-05T13:00:00-06:00', $starts[0]);
        $this->assertSame('2030-03-05T17:45:00-06:00', end($starts));
        $this->assertNotContains('2030-03-05T12:00:00-06:00', $starts);
        $this->assertSame([$this->daniela], array_values(array_unique(array_column($response->json('data.espacios'), 'barbero'))));
    }

    /** A part of the day with no free space of the barber is not available. */
    public function test_a_part_of_the_day_without_spaces_is_not_available(): void
    {
        $this->extract(new ExtractedReservation('degradado', 'Kevin', self::TUESDAY, dayPart: ExtractedDayPart::Evening));

        $this->propose()->assertOk()->assertJsonPath('data.estado', 'no_disponible')->assertJsonPath('data.campo', 'hora');
    }

    /** A phrase that leaves something out is incomplete, keeps the question of the first missing field and shows what is resolved. */
    public function test_an_incomplete_phrase_keeps_the_question_and_what_is_resolved(): void
    {
        $this->extract(new ExtractedReservation('degradado', 'Daniela', time: '10:00'));

        $this->propose()->assertOk()
            ->assertJsonPath('data.estado', 'incompleta')
            ->assertJsonPath('data.campo', 'fecha')
            ->assertJsonPath('data.campos_faltantes', ['fecha'])
            ->assertJsonPath('data.aclaracion', '¿Para qué día le gustaría reservar?')
            ->assertJsonPath('data.validada', false)
            ->assertJsonPath('data.servicio_id', $this->degradado)
            ->assertJsonPath('data.barbero_id', $this->daniela)
            ->assertJsonPath('data.precio', 7000);

        $this->extract(new ExtractedReservation(date: self::TUESDAY, time: '10:00'));
        $this->propose()->assertOk()
            ->assertJsonPath('data.estado', 'incompleta')
            ->assertJsonPath('data.campo', 'servicio')
            ->assertJsonPath('data.servicio_id', null)
            ->assertJsonPath('data.aclaracion', '¿Qué servicio le gustaría reservar?');
    }

    /** The proposal only reads: no reservation, no history, no membership and no blocked space, valid or not. */
    public function test_a_proposal_never_creates_a_reservation_or_blocks_a_space(): void
    {
        $reservations = $this->countIn('reservations', $this->puraNavaja);
        $history = $this->countIn('reservation_status_history', $this->puraNavaja);
        $memberships = DB::table('memberships')->where('user_id', $this->ana)->count();

        foreach ([new ExtractedReservation('degradado', 'Daniela', self::TUESDAY, '10:00'), new ExtractedReservation('degradado', null, self::TUESDAY, '10:00'), new ExtractedReservation('degradado', 'Daniela', self::TUESDAY, '12:00')] as $outcome) {
            $this->extract($outcome);
            $this->propose()->assertOk();
        }

        $this->assertSame($reservations, $this->countIn('reservations', $this->puraNavaja));
        $this->assertSame($history, $this->countIn('reservation_status_history', $this->puraNavaja));
        $this->assertSame($memberships, DB::table('memberships')->where('user_id', $this->ana)->count());

        $this->extract(new ExtractedReservation('degradado', 'Daniela', self::TUESDAY, '10:00'));
        $this->propose()->assertJsonPath('data.estado', 'valida');
        $luis = $this->insertUser('Luis Mora');
        $this->actAs($luis);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-03-05T10:00:00-06:00'))->assertCreated();
    }

    /** A service name with an injection attempt breaks nothing, matches nothing and leaves the tables as they were. */
    public function test_a_name_with_an_injection_attempt_breaks_nothing(): void
    {
        $services = $this->countIn('services', $this->puraNavaja);

        foreach (["corte'; drop table services; --", "' OR '1'='1", 'corte\\"; DELETE FROM reservations; --'] as $text) {
            $this->extract(new ExtractedReservation($text, null, self::TUESDAY, '10:00'));
            $this->propose()->assertOk()->assertJsonPath('data.estado', 'no_reconocida')->assertJsonPath('data.servicio_id', null);

            $this->extract(new ExtractedReservation('degradado', $text, self::TUESDAY, '10:00'));
            $this->propose()->assertOk()->assertJsonPath('data.estado', 'no_reconocida')->assertJsonPath('data.campo', 'barbero');
        }

        $this->assertSame($services, $this->countIn('services', $this->puraNavaja));
    }

    /** Authorize a collaborator to offer a service. */
    private function offer(string $collaboratorId, string $serviceId): void
    {
        DB::table('collaborator_services')->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $this->puraNavaja, 'collaborator_id' => $collaboratorId, 'service_id' => $serviceId]);
    }

    /** Bind a fake extractor that answers what the test says. */
    private function extract(ExtractedReservation $outcome): void
    {
        $this->app->instance(ReservationExtractor::class, new FakeReservationExtractor($outcome));
    }

    /**
     * Send any phrase to the proposal of a barbershop, Pura Navaja by default.
     *
     * @return TestResponse<JsonResponse>
     */
    private function propose(?string $barbershopId = null): TestResponse
    {
        return $this->postJson($this->reservationsUrl($barbershopId ?? $this->puraNavaja).'/propuesta', ['frase' => 'cualquier frase']);
    }
}
