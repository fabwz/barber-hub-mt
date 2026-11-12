<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

/**
 * What the system understood of a phrase (RF-37). It is a proposal that nothing has checked: the names are the ones the person said and
 * not identifiers, and neither the catalog nor the availability were consulted (IA-02 is the next issue). Nothing is created (IA-03).
 */
final readonly class ReservationProposal
{
    /**
     * @param  ?string  $service  The service as the person named it.
     * @param  ?string  $collaborator  The barber as the person named them.
     * @param  ?string  $date  The day as `YYYY-MM-DD`, never in the past.
     * @param  ?string  $time  The time as `HH:MM`, 24 hours.
     * @param  ?ExtractedDayPart  $dayPart  The part of the day, only when there is no exact time.
     * @param  list<ProposalField>  $missing  What the person still has to say, in the order to ask it.
     */
    public function __construct(
        public ?string $service,
        public ?string $collaborator,
        public ?string $date,
        public ?string $time,
        public ?ExtractedDayPart $dayPart,
        public array $missing,
    ) {}
}
