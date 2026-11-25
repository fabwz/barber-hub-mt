<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Src\Notifications\LiveUpdates\Infrastructure\Broadcasting\AgendaChanged;
use Src\Notifications\LiveUpdates\Infrastructure\Broadcasting\OfferChanged;
use Src\Reservations\Reservations\Application\UseCases\ConfirmReservation;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;
use Src\Waitlist\Offers\Infrastructure\Jobs\ExpireOfferJob;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Waitlist\SeedsOffers;
use Tests\Support\Waitlist\SeedsWaitlist;
use Tests\TestCase;

/**
 * The pop-up of an offer from the HTTP request to the queue (RF-35, NO-08, LE-04, MT-02), with the queue faked and the connection of broadcasting
 * `null`: what is left in the `realtime` queue after each step of an offer, for which channel and with what payload. Nothing reaches a WebSocket
 * server. Today is Friday 1 March 2030, 8:00: a reservation was cancelled, and its space was offered to Ana, the first of three, until 8:15.
 */
final class LiveOfferEventsTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsOffers;
    use SeedsReservations;
    use SeedsWaitlist;

    private string $offer;

    private QueueFake $queue;

    /** The space is freed by a cancellation, so the offer reaches Ana through the queue, and the queue holds what was published until then. */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->queue = Queue::fake([BroadcastEvent::class]);
        $this->seedOffers();
        $this->actAs($this->booker);
        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();
        $this->offer = $this->offerRows()[0]->id;
        $this->actAs($this->ana);
    }

    /** The offer is announced to the person it is made to, in their own channel and in no other (RF-35, NO-08). */
    public function test_the_offer_is_announced_in_the_channel_of_its_person_only(): void
    {
        $events = $this->offerEvents();

        $this->assertCount(1, $events);
        $this->assertSame(["private-usuario.{$this->ana}"], $this->channelsOf($events[0]));
        $this->assertSame('oferta.creada', $events[0]->broadcastAs());
        $this->assertSame(
            ['oferta' => $this->offer, 'barberia' => $this->puraNavaja, 'vence_en' => '2030-03-01T08:15:00-06:00'],
            $events[0]->broadcastWith(),
        );
        $this->assertSame('realtime', $events[0]->broadcastQueue());
    }

    /** The payload names no person, contact, service, price or reservation, and no other person's channel hears of it (NO-08). */
    public function test_nothing_personal_travels_and_nobody_else_hears_it(): void
    {
        foreach ($this->offerEvents() as $event) {
            $json = (string) json_encode($event->broadcastWith());

            foreach (['Ana', 'Rojas', 'Luis', 'Bea', '@', $this->anaMembership, $this->anaEntry, $this->daniela, $this->degradado, $this->ana] as $secret) {
                $this->assertStringNotContainsString($secret, $json);
            }

            $this->assertNotContains("private-usuario.{$this->luis}", $this->channelsOf($event));
            $this->assertNotContains("private-usuario.{$this->bea}", $this->channelsOf($event));
        }
    }

    /** Turning the offer down closes it with `rechazada` for Ana, and the next person hears their own offer (RF-32, LE-05). */
    public function test_rejecting_closes_the_offer_and_the_next_person_gets_theirs(): void
    {
        $this->postJson($this->answerUrl('rechazo'))->assertOk();

        $this->assertCount(2, $this->eventsNamed('oferta.creada'));
        $this->assertSame([["private-usuario.{$this->ana}"], ["private-usuario.{$this->luis}"]], array_map($this->channelsOf(...), $this->eventsNamed('oferta.creada')));
        $closed = $this->closedEvents();
        $this->assertCount(1, $closed);
        $this->assertSame(["private-usuario.{$this->ana}"], $this->channelsOf($closed[0]));
        $this->assertSame(['oferta' => $this->offer, 'motivo' => 'rechazada'], $closed[0]->broadcastWith());
    }

    /** Turning it down twice is idempotent and closes it once. */
    public function test_rejecting_twice_closes_once(): void
    {
        $this->postJson($this->answerUrl('rechazo'))->assertOk();
        $this->postJson($this->answerUrl('rechazo'))->assertOk();

        $this->assertCount(1, $this->eventsNamed('oferta.cerrada'));
    }

    /** Accepting in a barbershop that charges nothing confirms the reservation, accepts the offer and closes it with `aceptada` (RF-32, RE-07). */
    public function test_accepting_closes_the_offer_as_accepted(): void
    {
        $this->postJson($this->answerUrl('aceptacion'))->assertOk()->assertJsonPath('data.estado', 'aceptada');

        $closed = $this->closedEvents();
        $this->assertCount(1, $closed);
        $this->assertSame(["private-usuario.{$this->ana}"], $this->channelsOf($closed[0]));
        $this->assertSame(['oferta' => $this->offer, 'motivo' => 'aceptada'], $closed[0]->broadcastWith());
    }

    /**
     * With a payment the offer stays open until the gateway confirms it: accepting closes nothing, and the confirmed payment closes it with
     * `aceptada` (PA-06). The browser's word does nothing.
     */
    public function test_a_payment_closes_the_offer_when_it_is_confirmed(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_gateway' => 'onvo', 'payment_mode' => 'deposit', 'deposit_percentage' => 30]);
        $reservation = $this->postJson($this->answerUrl('aceptacion'))->assertOk()->json('data.reserva');

        $this->assertSame(0, count($this->closedEvents()));

        $this->app->make(BarbershopProcessScope::class)->run($this->puraNavaja, fn () => $this->app->make(ConfirmReservation::class)->execute($reservation, $this->clock->now()));

        $closed = $this->closedEvents();
        $this->assertCount(1, $closed);
        $this->assertSame('aceptada', $closed[0]->broadcastWith()['motivo']);
    }

    /**
     * Cancelling the temporary reservation that was made for the offer closes the pop-up with `rechazada`, as turning it down does (LE-05): once, in the
     * channel of Ana only, and the next person is offered the space in theirs.
     */
    public function test_cancelling_the_reservation_of_the_offer_closes_it_as_rejected(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_gateway' => 'onvo', 'payment_mode' => 'deposit', 'deposit_percentage' => 30]);
        $reservation = $this->postJson($this->answerUrl('aceptacion'))->assertOk()->json('data.reserva');
        $this->assertCount(0, $this->closedEvents());

        $this->postJson($this->cancelUrlOf($reservation))->assertOk();
        $this->postJson($this->cancelUrlOf($reservation))->assertOk();

        $closed = $this->closedEvents();
        $this->assertCount(1, $closed);
        $this->assertSame(["private-usuario.{$this->ana}"], $this->channelsOf($closed[0]));
        $this->assertSame(['oferta' => $this->offer, 'motivo' => 'rechazada'], $closed[0]->broadcastWith());
        $this->assertSame([["private-usuario.{$this->ana}"], ["private-usuario.{$this->luis}"]], array_map($this->channelsOf(...), $this->eventsNamed('oferta.creada')));
    }

    /** An offer that runs out its 15 minutes closes with `vencida` for Ana, and the next person gets theirs (LE-04, LE-05). */
    public function test_an_expired_offer_closes_as_expired(): void
    {
        $this->setNow('2030-03-01 08:15:01');
        $this->app->call((new ExpireOfferJob($this->offer, $this->puraNavaja))->handle(...));

        $closed = $this->closedEvents();
        $this->assertCount(1, $closed);
        $this->assertSame(["private-usuario.{$this->ana}"], $this->channelsOf($closed[0]));
        $this->assertSame(['oferta' => $this->offer, 'motivo' => 'vencida'], $closed[0]->broadcastWith());
        $this->assertSame([["private-usuario.{$this->ana}"], ["private-usuario.{$this->luis}"]], array_map($this->channelsOf(...), $this->eventsNamed('oferta.creada')));
    }

    /** A task that runs early or twice closes nothing: the pop-up does not disappear before its time. */
    public function test_an_early_or_repeated_expiry_closes_nothing(): void
    {
        $this->app->call((new ExpireOfferJob($this->offer, $this->puraNavaja))->handle(...));
        $this->assertCount(0, $this->eventsNamed('oferta.cerrada'));

        $this->setNow('2030-03-01 08:15:01');
        $this->app->call((new ExpireOfferJob($this->offer, $this->puraNavaja))->handle(...));
        $this->app->call((new ExpireOfferJob($this->offer, $this->puraNavaja))->handle(...));

        $this->assertCount(1, $this->eventsNamed('oferta.cerrada'));
    }

    /** An answer that is refused rolls back and publishes nothing: nobody hears of something that was undone. */
    public function test_a_refused_answer_publishes_nothing(): void
    {
        $this->setNow('2030-03-01 08:15:01');

        $this->postJson($this->answerUrl('aceptacion'))->assertConflict();
        $this->postJson($this->answerUrl('rechazo'))->assertConflict();

        $this->assertCount(0, $this->eventsNamed('oferta.cerrada'));
        $this->assertCount(1, $this->offerEvents());
    }

    /** The offers of the live events are the only ones of the user channel: the agenda keeps its own channels. */
    public function test_the_agenda_events_do_not_travel_in_the_channel_of_a_person(): void
    {
        foreach ($this->queue->pushed(BroadcastEvent::class)->all() as $job) {
            if ($job->event instanceof AgendaChanged) {
                foreach ($job->event->broadcastOn() as $channel) {
                    $this->assertStringStartsNotWith('private-usuario.', $channel->name);
                }
            }
        }
    }

    private function answerUrl(string $answer): string
    {
        return "/api/v1/barberias/{$this->puraNavaja}/ofertas/{$this->offer}/{$answer}";
    }

    /**
     * The events of the offers left in the queue, in order.
     *
     * @return list<OfferChanged>
     */
    private function offerEvents(): array
    {
        $events = [];

        foreach ($this->queue->pushed(BroadcastEvent::class)->all() as $job) {
            if ($job->event instanceof OfferChanged) {
                $events[] = $job->event;
            }
        }

        return $events;
    }

    /**
     * The closings left in the queue.
     *
     * @return list<OfferChanged>
     */
    private function closedEvents(): array
    {
        return $this->eventsNamed('oferta.cerrada');
    }

    /**
     * The events of the offers with a name, in order.
     *
     * @return list<OfferChanged>
     */
    private function eventsNamed(string $name): array
    {
        return array_values(array_filter($this->offerEvents(), static fn (OfferChanged $event): bool => $event->broadcastAs() === $name));
    }

    /**
     * The names of the channels that carry an event.
     *
     * @return list<string>
     */
    private function channelsOf(OfferChanged $event): array
    {
        return array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn());
    }
}
