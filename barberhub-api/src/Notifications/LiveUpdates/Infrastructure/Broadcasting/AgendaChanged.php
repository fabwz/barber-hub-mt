<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Infrastructure\Broadcasting;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Src\Notifications\LiveUpdates\Application\DTOs\AgendaChange;

/**
 * A change of the agenda, on its way to the browsers of the people who may see it (RF-36). It is published once the transaction that
 * caused it commits, so nobody hears of something that was undone, and it travels through the `realtime` queue, apart from the mail and the
 * waitlist, so a burst of notices never delays it past the 5 seconds of NO-06. Its payload holds ids, a status, a kind and times, nothing
 * personal: the browser asks the API for the detail. It is a plain bag of values, so it serializes into the queue without a model.
 */
final class AgendaChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    /** The queue of the live events, served by its own worker (`queue:work --queue=realtime --sleep=1`). */
    public const string QUEUE = 'realtime';

    /** The name of the event as the browser listens to it, with a leading dot because it has no namespace. */
    public const string NAME = 'reserva.actualizada';

    /** Attempts of the task that publishes it; a lost event is recovered by the resynchronization of the screen. */
    public int $tries = 3;

    /** Seconds before the next attempt. */
    public int $backoff = 1;

    /**
     * @param  list<string>  $channels  Names of the private channels, without their prefix.
     * @param  array<string, string|null>  $payload
     */
    private function __construct(private readonly array $channels, private readonly array $payload) {}

    /** Build the event of a change: the channels that carry it and the payload in the names of the API, with the times in the time zone of the application. */
    public static function from(AgendaChange $change, string $timezone): self
    {
        $zone = new DateTimeZone($timezone);
        $moment = static fn (DateTimeImmutable $at): string => $at->setTimezone($zone)->format(DATE_ATOM);
        $payload = [
            'reserva' => $change->reservationId,
            'colaborador' => $change->collaboratorId,
            'estado' => $change->status,
            'inicio' => $moment($change->startsAt),
            'fin' => $moment($change->endsAt),
            'cambio' => $change->kind,
        ];

        if ($change->previousCollaboratorId !== null) {
            $payload['colaborador_anterior'] = $change->previousCollaboratorId;
        }

        if ($change->previousStartsAt !== null) {
            $payload['inicio_anterior'] = $moment($change->previousStartsAt);
        }

        $payload['ocurrio_en'] = $moment($change->occurredAt);

        return new self($change->channels(), $payload);
    }

    /**
     * The private channels that carry it.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(static fn (string $name): PrivateChannel => new PrivateChannel($name), $this->channels);
    }

    /** The name the browser listens to. */
    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * What travels: the reservation, the collaborator, the status, the times, what changed and when.
     *
     * @return array<string, string|null>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }

    /** The queue where the task that publishes it waits. */
    public function broadcastQueue(): string
    {
        return self::QUEUE;
    }
}
