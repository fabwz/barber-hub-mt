<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Infrastructure\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Src\Notifications\LiveUpdates\Application\DTOs\AgendaChange;
use Src\Notifications\LiveUpdates\Infrastructure\Broadcasting\AgendaChanged;
use Src\Reservations\Reservations\Domain\Events\ReservationCancelled;
use Src\Reservations\Reservations\Domain\Events\ReservationClosed;
use Src\Reservations\Reservations\Domain\Events\ReservationConfirmed;
use Src\Reservations\Reservations\Domain\Events\ReservationCreated;
use Src\Reservations\Reservations\Domain\Events\ReservationExpired;
use Src\Reservations\Reservations\Domain\Events\ReservationRescheduled;
use Src\Shared\Domain\Contracts\Clock;

/**
 * Turns each change of a reservation into the live event of the agenda (RF-36, NO-06). It runs inside the transaction that made the change
 * and does no work of its own: it hands the event to the dispatcher, which holds it until the transaction commits and then leaves its
 * publication in the `realtime` queue, so nothing it does can undo a reservation. The status names are the ones of the API.
 */
final readonly class PublishAgendaChanges
{
    /** Receive the dispatcher and the clock that stamps the moment of the change. */
    public function __construct(private Dispatcher $events, private Clock $clock) {}

    /** A temporary reservation was created. */
    public function onCreated(ReservationCreated $event): void
    {
        $this->publish(new AgendaChange($event->barbershopId, $event->reservationId, $event->collaboratorId, 'temporal', $event->startsAt, $event->endsAt, 'creada', $this->clock->now()));
    }

    /** A reservation became confirmed, by whichever way, or was born confirmed. */
    public function onConfirmed(ReservationConfirmed $event): void
    {
        $this->publish(new AgendaChange($event->barbershopId, $event->reservationId, $event->collaboratorId, 'confirmada', $event->startsAt, $event->endsAt, 'confirmada', $this->clock->now()));
    }

    /** A reservation moved to another start or collaborator; the agenda that lost it hears it too. */
    public function onRescheduled(ReservationRescheduled $event): void
    {
        $this->publish(new AgendaChange(
            $event->barbershopId,
            $event->reservationId,
            $event->collaboratorId,
            'confirmada',
            $event->startsAt,
            $event->endsAt,
            'reprogramada',
            $this->clock->now(),
            $event->previousCollaboratorId,
            $event->previousStartsAt,
        ));
    }

    /** A reservation was cancelled, by the customer or by the barbershop. */
    public function onCancelled(ReservationCancelled $event): void
    {
        $status = $event->byBarbershop ? 'cancelada_por_barberia' : 'cancelada_por_cliente';

        $this->publish(new AgendaChange($event->barbershopId, $event->reservationId, $event->collaboratorId, $status, $event->startsAt, $event->endsAt, 'cancelada', $this->clock->now()));
    }

    /** A reservation was closed as attended or as an absence. */
    public function onClosed(ReservationClosed $event): void
    {
        $status = $event->status === 'no_show' ? 'ausente' : 'completada';

        $this->publish(new AgendaChange($event->barbershopId, $event->reservationId, $event->collaboratorId, $status, $event->startsAt, $event->endsAt, 'cerrada', $this->clock->now()));
    }

    /** A temporary reservation expired without payment. */
    public function onExpired(ReservationExpired $event): void
    {
        $this->publish(new AgendaChange($event->barbershopId, $event->reservationId, $event->collaboratorId, 'expirada', $event->startsAt, $event->endsAt, 'expirada', $this->clock->now()));
    }

    /** Hand the live event to the dispatcher, which waits for the commit. */
    private function publish(AgendaChange $change): void
    {
        $this->events->dispatch(AgendaChanged::from($change, config()->string('app.timezone')));
    }
}
