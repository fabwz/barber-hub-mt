<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Infrastructure\Broadcasting;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Src\Notifications\LiveUpdates\Application\UserChannels;

/**
 * An offer of the waitlist, on its way to the browser of the one person it was made to (RF-35, NO-08): it appears as a pop-up when it is made
 * and disappears when it expires or is answered. It is published once the transaction that caused it commits, through the `realtime` queue, as
 * the live agenda is. It travels only in the private channel of the account of that person, and its payload holds ids, a time and a reason, never
 * a name, a contact, a service or a price: the browser asks the API for the detail, where the offer is found among the person's own.
 */
final class OfferChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    /** The name of the event when an offer is made to the person, with a leading dot because it has no namespace. */
    public const string CREATED = 'oferta.creada';

    /** The name of the event when the offer ends: it expired, or the person accepted it or turned it down. */
    public const string CLOSED = 'oferta.cerrada';

    /** Why an offer closed, in the words of the API. */
    public const string EXPIRED = 'vencida';

    public const string ACCEPTED = 'aceptada';

    public const string REJECTED = 'rechazada';

    /** Attempts of the task that publishes it; a lost event is recovered when the screen asks for its offers again. */
    public int $tries = 3;

    /** Seconds before the next attempt. */
    public int $backoff = 1;

    /**
     * @param  array<string, string>  $payload
     */
    private function __construct(private readonly string $userId, private readonly string $name, private readonly array $payload) {}

    /** The offer that was made: its id, the barbershop that holds it and the moment it expires, in the time zone of the application. */
    public static function created(string $userId, string $offerId, string $barbershopId, DateTimeImmutable $expiresAt, string $timezone): self
    {
        return new self($userId, self::CREATED, [
            'oferta' => $offerId,
            'barberia' => $barbershopId,
            'vence_en' => $expiresAt->setTimezone(new DateTimeZone($timezone))->format(DATE_ATOM),
        ]);
    }

    /** The offer that ended and why: `vencida`, `aceptada` or `rechazada`. */
    public static function closed(string $userId, string $offerId, string $reason): self
    {
        return new self($userId, self::CLOSED, ['oferta' => $offerId, 'motivo' => $reason]);
    }

    /**
     * The private channel of the person, and no other.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(UserChannels::ofUser($this->userId))];
    }

    /** The name the browser listens to. */
    public function broadcastAs(): string
    {
        return $this->name;
    }

    /**
     * What travels.
     *
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }

    /** The queue where the task that publishes it waits, the one of the live agenda. */
    public function broadcastQueue(): string
    {
        return AgendaChanged::QUEUE;
    }
}
