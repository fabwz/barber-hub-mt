<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Application\DTOs;

use DateTimeImmutable;
use Src\Notifications\LiveUpdates\Application\AgendaChannels;

/**
 * A change of the agenda that the people who may see it hear about at once (RF-36): which reservation, whose agenda, in which status and
 * time, and what happened to it. It holds ids, a status, a kind and times, never the customer, the contact or the payment: whoever hears of
 * it asks the API for the detail, where the permissions decide what they see (CO-04, MT-02). A reschedule also holds the collaborator and the
 * start the reservation had, so the agenda that lost it can drop it.
 */
final readonly class AgendaChange
{
    /** Hold what happened, to whom and when; `status` is the name of the status in the API and `kind` what changed. */
    public function __construct(
        public string $barbershopId,
        public string $reservationId,
        public string $collaboratorId,
        public string $status,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public string $kind,
        public DateTimeImmutable $occurredAt,
        public ?string $previousCollaboratorId = null,
        public ?DateTimeImmutable $previousStartsAt = null,
    ) {}

    /**
     * The channels that carry the change: the agenda of the barbershop for its owner and the agenda of each collaborator that has or had the
     * reservation, for them alone (CO-04).
     *
     * @return list<string>
     */
    public function channels(): array
    {
        $channels = [
            AgendaChannels::ofBarbershop($this->barbershopId),
            AgendaChannels::ofCollaborator($this->barbershopId, $this->collaboratorId),
        ];

        if ($this->previousCollaboratorId !== null && $this->previousCollaboratorId !== $this->collaboratorId) {
            $channels[] = AgendaChannels::ofCollaborator($this->barbershopId, $this->previousCollaboratorId);
        }

        return $channels;
    }
}
