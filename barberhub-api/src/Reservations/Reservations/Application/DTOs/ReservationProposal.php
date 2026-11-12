<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

use DateTimeImmutable;
use Src\Reservations\Reservations\Domain\ValueObjects\SlotAlternative;
use Src\Shared\Domain\ValueObjects\Money;

/**
 * What the system understood of a phrase and whether it holds up against the real catalog and availability (RF-37, IA-02). The
 * text fields are what the person said; the identifiers, the start and the price come only from our own catalog and
 * availability, never from the provider. Nothing is created (IA-03).
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
     * @param  ProposalStatus  $status  Whether the proposal holds up.
     * @param  ?ProposalField  $field  The field that failed or is still open; none when the proposal is valid.
     * @param  ?string  $serviceId  The service of the catalog the text was resolved to.
     * @param  ?string  $collaboratorId  The barber of the catalog the text was resolved to.
     * @param  ?DateTimeImmutable  $startsAt  The free space to book, only for an exact time that is free.
     * @param  ?DateTimeImmutable  $endsAt  The end of that space, without the cleaning.
     * @param  ?Money  $price  The price of the service in the catalog.
     * @param  list<ProposalCandidate>  $candidates  What the person can choose when the proposal is ambiguous.
     * @param  list<SlotAlternative>  $spaces  The real free spaces that fit what the person asked.
     * @param  list<SlotAlternative>  $alternatives  The free spaces closest to the one asked, when it is not available (RE-02).
     */
    public function __construct(
        public ?string $service,
        public ?string $collaborator,
        public ?string $date,
        public ?string $time,
        public ?ExtractedDayPart $dayPart,
        public array $missing,
        public ProposalStatus $status = ProposalStatus::Incomplete,
        public ?ProposalField $field = null,
        public ?string $serviceId = null,
        public ?string $collaboratorId = null,
        public ?DateTimeImmutable $startsAt = null,
        public ?DateTimeImmutable $endsAt = null,
        public ?Money $price = null,
        public array $candidates = [],
        public array $spaces = [],
        public array $alternatives = [],
    ) {}
}
