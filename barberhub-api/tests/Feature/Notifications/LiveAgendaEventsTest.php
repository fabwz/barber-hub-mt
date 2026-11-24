<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Notifications\LiveUpdates\Application\DTOs\AgendaChange;
use Src\Notifications\LiveUpdates\Infrastructure\Broadcasting\AgendaChanged;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Shared\MutableClock;
use Tests\TestCase;

/**
 * The live agenda from the HTTP request to the queue (RF-36, NO-06, CO-04, MT-02), with the queue faked and the connection of broadcasting `null`: what is
 * left in the `realtime` queue after each change of a reservation, for which channels and with what payload. Nothing reaches a WebSocket server.
 * Today is Friday 1 March 2030, 8:00; Pura Navaja charges nothing unless a test says so.
 */
final class LiveAgendaEventsTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    /** The only members of the payload: ids, a status, a kind and times, never the customer, the contact or the payment. */
    private const array PAYLOAD_MEMBERS = ['reserva', 'colaborador', 'estado', 'inicio', 'fin', 'cambio', 'ocurrio_en'];

    private string $ana;

    private string $luis;

    private MutableClock $clock;

    private QueueFake $queue;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->queue = Queue::fake([BroadcastEvent::class]);
        $this->seedBookableBarbershops();
        $this->clock = new MutableClock(self::cr('2030-03-01 08:00'));
        $this->app->instance(Clock::class, $this->clock);
        $this->scopeQueriesTo($this->puraNavaja);
        [$this->ana] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        [$this->luis] = $this->insertCustomer($this->puraNavaja, 'Luis Mora');
    }

    private static function cr(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }

    /**
     * The live events left in the queue, in order.
     *
     * @return list<AgendaChanged>
     */
    private function changes(): array
    {
        return array_values(array_map(
            fn (BroadcastEvent $job): AgendaChanged => $job->event instanceof AgendaChanged ? $job->event : throw new \LogicException('Only the live agenda is broadcast.'),
            $this->queue->pushed(BroadcastEvent::class)->all(),
        ));
    }

    /**
     * The names of the private channels that carry an event, sorted.
     *
     * @return list<string>
     */
    private function channelsOf(AgendaChanged $event): array
    {
        $names = array_map(fn ($channel): string => (string) $channel->name, $event->broadcastOn());
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private function channelsOfBarbershop(string $barbershopId, string ...$collaborators): array
    {
        $names = ["private-barberia.{$barbershopId}.agenda", ...array_map(fn (string $id): string => "private-barberia.{$barbershopId}.colaboradores.{$id}", $collaborators)];
        sort($names);

        return $names;
    }

    private function charge(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'deposit', 'deposit_percentage' => 30, 'payment_gateway' => 'onvo']);
    }

    private function book(string $customer, string $startsAt = self::TUESDAY_TEN): string
    {
        $this->actAs($customer);

        return (string) $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: $startsAt))->assertCreated()->json('data.id');
    }

    /** A reservation confirmed on the spot reaches the agenda of the owner and the one of its collaborator, with the ids, the status and the times and nothing else. */
    public function test_a_confirmed_booking_reaches_the_owner_and_its_collaborator(): void
    {
        $id = $this->book($this->ana);

        $this->queue->assertPushedOn(AgendaChanged::QUEUE, BroadcastEvent::class);
        $changes = $this->changes();
        $this->assertCount(1, $changes);
        $this->assertSame($this->channelsOfBarbershop($this->puraNavaja, $this->daniela), $this->channelsOf($changes[0]));
        $this->assertSame([
            'reserva' => $id,
            'colaborador' => $this->daniela,
            'estado' => 'confirmada',
            'inicio' => '2030-03-05T10:00:00-06:00',
            'fin' => '2030-03-05T10:45:00-06:00',
            'cambio' => 'confirmada',
            'ocurrio_en' => '2030-03-01T08:00:00-06:00',
        ], $changes[0]->broadcastWith());
        $this->assertSame('reserva.actualizada', $changes[0]->broadcastAs());
        $this->assertSame('realtime', $changes[0]->broadcastQueue());
    }

    /** Nothing personal travels: not the name of the customer, nor their email, nor the price (CO-04, MT-02). */
    public function test_the_payload_carries_nothing_personal(): void
    {
        $this->book($this->ana);

        $payload = $this->changes()[0]->broadcastWith();
        $this->assertSame(self::PAYLOAD_MEMBERS, array_keys($payload));

        $email = (string) DB::table('users')->where('id', $this->ana)->value('email');

        foreach (['Ana Rojas', $email, '7000'] as $personal) {
            $this->assertStringNotContainsString($personal, (string) json_encode($payload));
        }
    }

    /** A temporary reservation is a new reservation of the agenda: it appears as created and temporary (RE-05). */
    public function test_a_temporary_booking_appears_as_created(): void
    {
        $this->charge();
        $id = $this->book($this->ana);

        $changes = $this->changes();
        $this->assertCount(1, $changes);
        $this->assertSame($this->channelsOfBarbershop($this->puraNavaja, $this->daniela), $this->channelsOf($changes[0]));
        $this->assertSame(['reserva' => $id, 'estado' => 'temporal', 'cambio' => 'creada'], array_intersect_key($changes[0]->broadcastWith(), ['reserva' => 1, 'estado' => 1, 'cambio' => 1]));
    }

    /** The temporary reservation that expires without payment leaves the agenda: it is announced as expired (RE-05). */
    public function test_an_expired_reservation_is_announced(): void
    {
        $this->charge();
        $id = $this->book($this->ana);
        $this->clock->moveTo(self::cr('2030-03-01 08:11'));
        $this->book($this->luis);

        $kinds = array_map(fn (AgendaChanged $event): string => (string) $event->broadcastWith()['cambio'], $this->changes());
        $this->assertSame(['creada', 'expirada', 'creada'], $kinds);
        $expired = $this->changes()[1]->broadcastWith();
        $this->assertSame($id, $expired['reserva']);
        $this->assertSame('expirada', $expired['estado']);
        $this->assertSame($this->channelsOfBarbershop($this->puraNavaja, $this->daniela), $this->channelsOf($this->changes()[1]));
    }

    /** A cancellation takes the reservation off the agenda, with who cancelled it in the status, and a repeated one says nothing (CA-06). */
    public function test_a_cancellation_is_announced_once(): void
    {
        $id = $this->book($this->ana);

        $this->actAs($this->owner);
        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$id}/cancelacion")->assertOk();
        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$id}/cancelacion")->assertOk();

        $changes = $this->changes();
        $this->assertCount(2, $changes);
        $this->assertEquals(['cambio' => 'cancelada', 'estado' => 'cancelada_por_barberia'], array_intersect_key($changes[1]->broadcastWith(), ['cambio' => 1, 'estado' => 1]));
        $this->assertSame($this->channelsOfBarbershop($this->puraNavaja, $this->daniela), $this->channelsOf($changes[1]));
    }

    /** The customer who cancels is told apart from the barbershop in the status. */
    public function test_a_cancellation_by_the_customer_says_so(): void
    {
        $id = $this->book($this->ana);

        $this->actAs($this->ana);
        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$id}/cancelacion")->assertOk();

        $this->assertSame('cancelada_por_cliente', $this->changes()[1]->broadcastWith()['estado']);
    }

    /** Moving a reservation to another collaborator reaches the one that gets it and the one that loses it, and says where it was (CO-04). */
    public function test_a_reschedule_to_another_collaborator_reaches_both_agendas(): void
    {
        $id = $this->book($this->ana);

        $this->actAs($this->owner);
        $this->patchJson($this->reservationsUrl($this->puraNavaja)."/{$id}", ['inicio' => '2030-03-05T11:00:00-06:00', 'barbero' => $this->kevin])->assertOk();

        $changes = $this->changes();
        $this->assertCount(2, $changes);
        $this->assertSame($this->channelsOfBarbershop($this->puraNavaja, $this->daniela, $this->kevin), $this->channelsOf($changes[1]));
        $this->assertSame([
            'reserva' => $id,
            'colaborador' => $this->kevin,
            'estado' => 'confirmada',
            'inicio' => '2030-03-05T11:00:00-06:00',
            'fin' => '2030-03-05T11:45:00-06:00',
            'cambio' => 'reprogramada',
            'colaborador_anterior' => $this->daniela,
            'inicio_anterior' => '2030-03-05T10:00:00-06:00',
            'ocurrio_en' => '2030-03-01T08:00:00-06:00',
        ], $changes[1]->broadcastWith());
    }

    /** Moving to another time with the same collaborator does not repeat their channel. */
    public function test_a_reschedule_with_the_same_collaborator_reaches_two_channels(): void
    {
        $id = $this->book($this->ana);

        $this->actAs($this->owner);
        $this->patchJson($this->reservationsUrl($this->puraNavaja)."/{$id}", ['inicio' => '2030-03-05T14:00:00-06:00'])->assertOk();

        $this->assertSame($this->channelsOfBarbershop($this->puraNavaja, $this->daniela), $this->channelsOf($this->changes()[1]));
    }

    /**
     * Closing a reservation announces the final status, once.
     *
     * @return array<string, array{string, string}>
     */
    public static function closings(): array
    {
        return [
            'attended' => ['atendida', 'completada'],
            'absence' => ['ausente', 'ausente'],
        ];
    }

    #[DataProvider('closings')]
    public function test_closing_announces_the_final_status_once(string $outcome, string $status): void
    {
        $id = $this->book($this->ana);
        $this->clock->moveTo(self::cr('2030-03-05 11:00'));

        $this->actAs($this->owner);
        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$id}/cierre", ['estado' => $outcome])->assertOk();
        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$id}/cierre", ['estado' => $outcome])->assertOk();

        $changes = $this->changes();
        $this->assertCount(2, $changes);
        $this->assertEquals(['cambio' => 'cerrada', 'estado' => $status], array_intersect_key($changes[1]->broadcastWith(), ['cambio' => 1, 'estado' => 1]));
        $this->assertSame($this->channelsOfBarbershop($this->puraNavaja, $this->daniela), $this->channelsOf($changes[1]));
    }

    /** A booking that fails, because the space is taken, leaves nothing in the queue: nobody hears of what did not happen. */
    public function test_a_refused_booking_announces_nothing(): void
    {
        $this->book($this->ana);

        $this->actAs($this->luis);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertStatus(409);

        $this->assertCount(1, $this->changes());
    }

    /** What happens in one barbershop is told only on its channels, never on the ones of another (MT-02, RF-36). */
    public function test_the_channels_of_a_barbershop_never_name_another(): void
    {
        $this->book($this->ana);
        $this->scopeQueriesTo($this->fadeLab);
        [$bea] = $this->insertCustomer($this->fadeLab, 'Bea Mora');
        $this->actAs($bea);
        $this->postJson($this->reservationsUrl($this->fadeLab), ['servicio' => $this->corte, 'barbero' => $this->mauricio, 'inicio' => self::TUESDAY_TEN])->assertCreated();

        $changes = $this->changes();
        $this->assertCount(2, $changes);
        $this->assertSame($this->channelsOfBarbershop($this->puraNavaja, $this->daniela), $this->channelsOf($changes[0]));
        $this->assertSame($this->channelsOfBarbershop($this->fadeLab, $this->mauricio), $this->channelsOf($changes[1]));

        foreach ($this->channelsOf($changes[1]) as $name) {
            $this->assertStringNotContainsString($this->puraNavaja, $name);
            $this->assertStringNotContainsString($this->daniela, $name);
        }
    }

    /** The event is published after the commit and goes through the queue of its own, with retries (NO-06). */
    public function test_the_event_waits_for_the_commit_and_has_its_own_queue(): void
    {
        $event = AgendaChanged::from(new AgendaChange(
            $this->puraNavaja,
            $this->degradado,
            $this->daniela,
            'confirmada',
            self::cr('2030-03-05 10:00'),
            self::cr('2030-03-05 10:45'),
            'confirmada',
            self::cr('2030-03-01 08:00'),
        ), 'America/Costa_Rica');

        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertSame('realtime', $event->broadcastQueue());
        $this->assertSame(3, $event->tries);
    }
}
