<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Absences;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Shared\MutableClock;
use Tests\TestCase;

/**
 * The owner blocks the agenda of a collaborator by hours or by days (RF-07, CO-06, DI-01, CA-04). Today is Friday 1 March 2030, 8:00;
 * Daniela works at Pura Navaja on Tuesdays from 9:00 to 19:00 with a break from 12:00 to 13:00 and Kevin from 9:00 to 12:00.
 */
final class ManageAbsencesTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;

    private const string TUESDAY = '2030-03-05';

    private MutableClock $clock;

    private string $ana;

    private string $luis;

    private string $collaboratorAccount;

    /** Seed the barbershops, an owner, two customers and the account of a collaborator. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->clock = new MutableClock(new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica')));
        $this->app->instance(Clock::class, $this->clock);
        $this->ana = $this->insertUser('Ana Rojas');
        $this->luis = $this->insertUser('Luis Mora');
        $this->scopeQueriesTo($this->puraNavaja);
        $this->collaboratorAccount = (string) DB::table('collaborators')->where('id', $this->kevin)->value('user_id');
    }

    /** A block from 14:00 to 16:00 takes those spaces from Daniela and leaves Kevin and the rest of her day alone (DI-01). */
    public function test_a_block_of_hours_removes_those_spaces_of_that_collaborator_only(): void
    {
        $kevinBefore = $this->starts($this->kevin);
        $danielaBefore = $this->starts($this->daniela);
        $this->assertNotEmpty(array_filter($danielaBefore, fn (string $start): bool => $start >= '14:00' && $start < '16:00'));

        $this->actAs($this->owner);
        $this->postJson($this->url(), $this->body())
            ->assertCreated()
            ->assertJsonPath('data.colaborador', $this->daniela)
            ->assertJsonPath('data.inicio', '2030-03-05T14:00:00-06:00')
            ->assertJsonPath('data.fin', '2030-03-05T16:00:00-06:00');

        $danielaAfter = $this->starts($this->daniela);
        $this->assertSame(
            [],
            array_filter($danielaAfter, fn (string $start): bool => $start < '16:00' && date('H:i', (int) strtotime("2000-01-01 {$start} +50 minutes")) > '14:00'),
            'No space of Daniela may overlap the block, cleaning included.',
        );
        $this->assertNotEmpty(array_filter($danielaAfter, fn (string $start): bool => $start >= '16:00'));
        $this->assertSame(array_values(array_filter($danielaBefore, fn (string $start): bool => $start < '13:00')), array_values(array_filter($danielaAfter, fn (string $start): bool => $start < '13:00')));
        $this->assertSame($kevinBefore, $this->starts($this->kevin));
    }

    /** The search that does not name a collaborator also loses only the spaces of the blocked one. */
    public function test_the_search_without_collaborator_loses_only_the_blocked_ones(): void
    {
        $this->actAs($this->owner);
        $this->postJson($this->url(), $this->body(from: '09:00', to: '19:00'))->assertCreated();

        $response = $this->getJson("/api/v1/barberias/{$this->puraNavaja}/disponibilidad?".http_build_query(['servicio' => $this->degradado, 'fecha' => self::TUESDAY]))->assertOk();

        $this->assertSame([$this->kevin], array_column($response->json('data.barberos'), 'id'));
    }

    /** A block of several days removes every space of those days and leaves the next ones (same behavior as a block of hours). */
    public function test_a_block_of_several_days_removes_the_whole_days(): void
    {
        $this->actAs($this->owner);
        $this->postJson($this->url(), ['colaborador' => $this->daniela, 'fecha_desde' => self::TUESDAY, 'fecha_hasta' => '2030-03-06', 'motivo' => 'Vacaciones'])
            ->assertCreated()
            ->assertJsonPath('data.inicio', '2030-03-05T00:00:00-06:00')
            ->assertJsonPath('data.fin', '2030-03-07T00:00:00-06:00')
            ->assertJsonPath('data.motivo', 'Vacaciones');

        $this->assertSame([], $this->starts($this->daniela));
        $this->assertNotEmpty($this->starts($this->daniela, '2030-03-12'));
    }

    /** A confirmed reservation inside the block stops the save, the error lists it and nothing is stored (CO-06). */
    public function test_a_confirmed_reservation_inside_the_block_is_refused_and_listed(): void
    {
        $reservation = $this->book($this->ana, '2030-03-05T14:00:00-06:00');

        $this->actAs($this->owner);
        $response = $this->postJson($this->url(), $this->body())
            ->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', $this->problem('ausencia-con-reservas'))
            ->assertJsonPath('total_reservas', 1)
            ->assertJsonPath('reservas.0.id', $reservation)
            ->assertJsonPath('reservas.0.inicio', '2030-03-05T14:00:00-06:00')
            ->assertJsonPath('reservas.0.servicio', 'Degradado')
            ->assertJsonPath('reservas.0.cliente', 'Ana Rojas')
            ->assertJsonPath('reservas.0.estado', 'confirmada');
        $this->assertStringNotContainsString('@', (string) $response->getContent());
        $this->assertSame(0, $this->countIn('collaborator_absences', $this->puraNavaja));
    }

    /** The owner resolves the reservation with the actions that exist (cancel for the barbershop) and saves again (CA-04). */
    public function test_after_cancelling_the_reservation_the_block_is_saved(): void
    {
        $reservation = $this->book($this->ana, '2030-03-05T14:00:00-06:00');
        $this->actAs($this->owner);
        $this->postJson($this->url(), $this->body())->assertConflict();

        $this->postJson("{$this->reservationsUrl($this->puraNavaja)}/{$reservation}/cancelacion")->assertOk();
        $this->postJson($this->url(), $this->body())->assertCreated();

        $this->assertSame(1, $this->countIn('collaborator_absences', $this->puraNavaja));
    }

    /** Rescheduling the reservation out of the block also resolves it. */
    public function test_after_rescheduling_the_reservation_the_block_is_saved(): void
    {
        $reservation = $this->book($this->ana, '2030-03-05T14:00:00-06:00');
        $this->actAs($this->owner);
        $this->postJson($this->url(), $this->body())->assertConflict();

        $this->patchJson("{$this->reservationsUrl($this->puraNavaja)}/{$reservation}", ['inicio' => '2030-03-05T17:00:00-06:00'])->assertOk();
        $this->postJson($this->url(), $this->body())->assertCreated();
    }

    /** A reservation of another collaborator or outside the hours does not count. */
    public function test_reservations_outside_the_block_do_not_count(): void
    {
        $this->book($this->ana, '2030-03-05T10:00:00-06:00');
        $this->book($this->luis, '2030-03-05T10:00:00-06:00', $this->kevin);

        $this->actAs($this->owner);
        $this->postJson($this->url(), $this->body())->assertCreated();
    }

    /** A temporary reservation with its payment window open counts as a conflict; once the window closes it does not (DI-05). */
    public function test_a_temporary_reservation_counts_only_while_its_window_is_open(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'deposit', 'deposit_percentage' => 30, 'payment_gateway' => 'onvo']);
        $reservation = $this->book($this->ana, '2030-03-05T14:00:00-06:00');
        $this->actAs($this->owner);

        $this->postJson($this->url(), $this->body())
            ->assertConflict()
            ->assertJsonPath('type', $this->problem('ausencia-con-reservas'))
            ->assertJsonPath('reservas.0.id', $reservation)
            ->assertJsonPath('reservas.0.estado', 'temporal');

        $this->clock->moveTo(new DateTimeImmutable('2030-03-01 08:11:00', new DateTimeZone('America/Costa_Rica')));
        $this->postJson($this->url(), $this->body())->assertCreated();
    }

    /** A cancelled reservation holds nothing and does not count. */
    public function test_a_cancelled_reservation_does_not_count(): void
    {
        $reservation = $this->book($this->ana, '2030-03-05T14:00:00-06:00');
        $this->actAs($this->owner);
        $this->postJson("{$this->reservationsUrl($this->puraNavaja)}/{$reservation}/cancelacion")->assertOk();

        $this->postJson($this->url(), $this->body())->assertCreated();
    }

    /** The owner sees the blocks that have not ended, the nearest first, and none of another barbershop; then removes one and the spaces return. */
    public function test_the_owner_lists_and_removes_the_current_blocks(): void
    {
        $this->actAs($this->owner);
        $later = $this->postJson($this->url(), ['colaborador' => $this->kevin, 'fecha_desde' => '2030-03-19', 'fecha_hasta' => '2030-03-19'])->json('data.id');
        $first = $this->postJson($this->url(), $this->body())->json('data.id');
        $this->insertAbsence($this->fadeLab, $this->mauricio, '2030-03-05 00:00:00-06', '2030-03-06 00:00:00-06');
        $this->insertAbsence($this->puraNavaja, $this->daniela, '2030-02-01 00:00:00-06', '2030-02-02 00:00:00-06');

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first)
            ->assertJsonPath('data.1.id', $later);

        $this->deleteJson($this->url($first))->assertNoContent();

        $this->getJson($this->url())->assertJsonCount(1, 'data');
        $this->assertNotEmpty(array_filter($this->starts($this->daniela), fn (string $start): bool => $start >= '14:00' && $start < '16:00'));
    }

    /** Someone else's block, or one that does not exist, is not found and stays where it was. */
    public function test_removing_an_unknown_or_foreign_block_is_not_found(): void
    {
        $foreign = $this->insertAbsence($this->fadeLab, $this->mauricio, '2030-03-05 00:00:00-06', '2030-03-06 00:00:00-06');
        $this->actAs($this->owner);

        $this->deleteJson($this->url($foreign))->assertNotFound()->assertJsonPath('type', $this->problem('no-encontrado'));
        $this->deleteJson($this->url((string) Str::uuid7()))->assertNotFound();
        $this->assertSame(1, $this->countIn('collaborator_absences', $this->fadeLab));
    }

    /** A collaborator of another barbershop is not found, and an inactive one is a conflict. */
    public function test_the_collaborator_must_be_an_active_one_of_the_barbershop(): void
    {
        $this->actAs($this->owner);

        $this->postJson($this->url(), $this->body($this->mauricio))->assertNotFound()->assertJsonPath('type', $this->problem('no-encontrado'));

        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('collaborators')->where('id', $this->kevin)->update(['is_active' => false]);
        $this->postJson($this->url(), $this->body($this->kevin))->assertConflict()->assertJsonPath('type', $this->problem('conflicto'));
        $this->assertSame(0, $this->countIn('collaborator_absences', $this->puraNavaja));
    }

    /**
     * Bodies the request or the domain refuse, with the field the error must name.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): array
    {
        $base = ['fecha_desde' => '2030-03-05', 'fecha_hasta' => '2030-03-05'];

        return [
            'without a collaborator' => [$base, 'colaborador'],
            'collaborator that is not an identifier' => [[...$base, 'colaborador' => 'kevin'], 'colaborador'],
            'without dates' => [['colaborador' => '0192f3a0-0000-7000-8000-000000000001'], 'fecha_desde'],
            'date with slashes' => [['colaborador' => '0192f3a0-0000-7000-8000-000000000001', 'fecha_desde' => '05/03/2030', 'fecha_hasta' => '2030-03-05'], 'fecha_desde'],
            'only one hour' => [[...$base, 'hora_desde' => '14:00'], 'hora_hasta'],
            'hour that does not exist' => [[...$base, 'hora_desde' => '25:00', 'hora_hasta' => '26:00'], 'hora_desde'],
            'reason over 200 characters' => [[...$base, 'motivo' => str_repeat('a', 201)], 'motivo'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalidBodies')]
    public function test_an_invalid_body_is_refused_naming_the_field(array $body, string $field): void
    {
        $this->actAs($this->owner);

        $this->postJson($this->url(), $body)
            ->assertUnprocessable()
            ->assertJsonPath('type', $this->problem('datos-invalidos'))
            ->assertJsonValidationErrorFor($field, 'errores');
    }

    /** A range that ends before it starts, or that is over, is refused by the domain with the field to blame. */
    public function test_a_wrong_range_is_refused_by_the_domain(): void
    {
        $this->actAs($this->owner);

        $this->postJson($this->url(), $this->body(from: '16:00', to: '14:00'))->assertUnprocessable()->assertJsonValidationErrorFor('hora_hasta', 'errores');
        $this->postJson($this->url(), ['colaborador' => $this->daniela, 'fecha_desde' => '2030-03-06', 'fecha_hasta' => '2030-03-05'])->assertUnprocessable()->assertJsonValidationErrorFor('fecha_hasta', 'errores');
        $this->postJson($this->url(), ['colaborador' => $this->daniela, 'fecha_desde' => '2030-02-28', 'fecha_hasta' => '2030-02-28'])->assertUnprocessable()->assertJsonValidationErrorFor('fecha_hasta', 'errores');
        $this->assertSame(0, $this->countIn('collaborator_absences', $this->puraNavaja));
    }

    /** Only the owner: a collaborator and a customer get 403, a stranger and the owner of another barbershop 404, nobody without a session 401. */
    public function test_only_the_owner_reaches_the_blocks(): void
    {
        $id = $this->insertAbsence($this->puraNavaja, $this->daniela, '2030-03-05 00:00:00-06', '2030-03-06 00:00:00-06');
        $this->getJson($this->url())->assertUnauthorized();

        $this->grantRole($this->luis, $this->puraNavaja, 'customer');
        $stranger = $this->insertUser('Dani');
        $foreignOwner = $this->insertUser('Dueño de Fade Lab');
        $this->grantRole($foreignOwner, $this->fadeLab, 'owner');

        foreach ([$this->collaboratorAccount, $this->luis] as $person) {
            $this->actAs($person);
            $this->getJson($this->url())->assertForbidden();
            $this->postJson($this->url(), $this->body())->assertForbidden();
            $this->deleteJson($this->url($id))->assertForbidden();
        }

        foreach ([$stranger, $foreignOwner] as $person) {
            $this->actAs($person);
            $this->getJson($this->url())->assertNotFound();
            $this->postJson($this->url(), $this->body())->assertNotFound();
            $this->deleteJson($this->url($id))->assertNotFound();
        }

        $this->assertSame(1, $this->countIn('collaborator_absences', $this->puraNavaja));
    }

    /** The block of Pura Navaja takes nothing from Fade Lab, whose collaborator also has the same day. */
    public function test_a_block_does_not_reach_another_barbershop(): void
    {
        $this->actAs($this->owner);
        $this->postJson($this->url(), $this->body(from: '09:00', to: '19:00'))->assertCreated();

        $this->assertNotEmpty($this->starts($this->mauricio, self::TUESDAY, $this->fadeLab, $this->corte));
    }

    /**
     * The starting hours of the spaces a collaborator offers a day.
     *
     * @return list<string>
     */
    private function starts(string $collaboratorId, string $date = self::TUESDAY, ?string $barbershopId = null, ?string $serviceId = null): array
    {
        $query = http_build_query(['servicio' => $serviceId ?? $this->degradado, 'fecha' => $date, 'barbero' => $collaboratorId]);
        $response = $this->getJson('/api/v1/barberias/'.($barbershopId ?? $this->puraNavaja)."/disponibilidad?{$query}")->assertOk();
        $slots = $response->json('data.barberos.0.espacios') ?? [];

        return array_values(array_map(fn (array $slot): string => substr((string) $slot['inicio'], 11, 5), $slots));
    }

    /** Book a space of the Tuesday as a customer and return the reservation. */
    private function book(string $customer, string $startsAt, ?string $collaboratorId = null): string
    {
        $this->actAs($customer);

        return (string) $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(null, $collaboratorId, $startsAt))->assertCreated()->json('data.id');
    }

    /**
     * The body of a block of Daniela on the Tuesday, from 14:00 to 16:00 unless other hours are given.
     *
     * @return array<string, string>
     */
    private function body(?string $collaborator = null, string $from = '14:00', string $to = '16:00'): array
    {
        return ['colaborador' => $collaborator ?? $this->daniela, 'fecha_desde' => self::TUESDAY, 'fecha_hasta' => self::TUESDAY, 'hora_desde' => $from, 'hora_hasta' => $to];
    }

    private function url(?string $absenceId = null): string
    {
        return "/api/v1/barberias/{$this->puraNavaja}/administracion/ausencias".($absenceId === null ? '' : "/{$absenceId}");
    }

    private function insertAbsence(string $barbershopId, string $collaboratorId, string $from, string $to): string
    {
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('collaborator_absences')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'collaborator_id' => $collaboratorId, 'starts_at' => $from, 'ends_at' => $to]);

        return $id;
    }

    private function problem(string $type): string
    {
        return rtrim((string) config('app.url'), '/').'/problemas/'.$type;
    }
}
