<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\Contracts\ProposableCollaborators;
use Src\Reservations\Reservations\Application\Contracts\ProposalAvailability;
use Src\Reservations\Reservations\Application\DTOs\ExtractedReservation;
use Src\Reservations\Reservations\Application\DTOs\ProposableCollaborator;
use Src\Reservations\Reservations\Application\DTOs\ProposableService;
use Src\Reservations\Reservations\Application\DTOs\ProposalCandidate;
use Src\Reservations\Reservations\Application\DTOs\ProposalField;
use Src\Reservations\Reservations\Application\DTOs\ProposalStatus;
use Src\Reservations\Reservations\Application\DTOs\ReservationProposal;
use Src\Reservations\Reservations\Domain\Services\NameMatcher;
use Src\Reservations\Reservations\Domain\ValueObjects\SlotAlternative;

/**
 * The validation stage of a proposal (IA-02, DI-01): the words of the person are resolved against the active catalog of the
 * barbershop and the space is checked with the availability that customers already see. The provider only ever gave text; the
 * identifiers, the price and the free spaces that come out of here are ours. It reads and never writes (IA-03).
 */
final readonly class ValidateProposal
{
    /** Receive the matcher, the port to the barbers who offer a service and the port to the free spaces. */
    public function __construct(
        private NameMatcher $matcher,
        private ProposableCollaborators $collaborators,
        private ProposalAvailability $availability,
    ) {}

    /**
     * Check a normalized proposal, in this order: the service, the barber, what is still missing and the space. It stops at the
     * first thing that fails and says which field it was. A name matches the catalog when it is equal to one name or, if there is
     * no such name, contained in exactly one (as whole words); several matches are an ambiguity and none is an unknown name. A
     * barber is looked for only among the active ones who offer the service. With an exact time it asks the availability for
     * that start; with a part of the day, for the spaces that start inside it. Without a barber it never picks one: the ones
     * who are free are offered to the person (DI-04 belongs to the booking of #54, which still asks for the barber).
     *
     * @param  list<ProposalField>  $missing  What the normalization found the phrase leaves out.
     * @param  list<ProposableService>  $services  The active services of the barbershop.
     */
    public function execute(ExtractedReservation $said, array $missing, array $services, DateTimeImmutable $now): ReservationProposal
    {
        $service = null;

        if ($said->service !== null) {
            $ids = $this->matcher->match($said->service, $this->names($services));

            if ($ids === []) {
                return $this->result($said, $missing, ProposalStatus::Unrecognized, ProposalField::Service);
            }

            $matches = array_values(array_filter($services, fn (ProposableService $candidate): bool => in_array($candidate->id, $ids, true)));

            if (count($matches) > 1) {
                return $this->result($said, $missing, ProposalStatus::Ambiguous, ProposalField::Service, candidates: array_map(
                    fn (ProposableService $match): ProposalCandidate => new ProposalCandidate($match->id, $match->name),
                    $matches,
                ));
            }

            $service = $matches[0];
        }

        $staff = $service === null ? [] : $this->collaborators->offering($service->id);
        $collaborator = null;

        if ($service !== null && $said->collaborator !== null) {
            $ids = $this->matcher->match($said->collaborator, $this->names($staff));

            if ($ids === []) {
                return $this->result($said, $missing, ProposalStatus::Unrecognized, ProposalField::Collaborator, $service);
            }

            $matches = array_values(array_filter($staff, fn (ProposableCollaborator $candidate): bool => in_array($candidate->id, $ids, true)));

            if (count($matches) > 1) {
                return $this->result($said, $missing, ProposalStatus::Ambiguous, ProposalField::Collaborator, $service, candidates: $this->candidates($matches));
            }

            $collaborator = $matches[0];
        }

        if ($missing !== [] || $service === null || $said->date === null) {
            return $this->result($said, $missing, ProposalStatus::Incomplete, $missing[0] ?? ProposalField::Service, $service, $collaborator);
        }

        $zone = $now->getTimezone();
        $free = $this->availability->onDay($service->id, $collaborator?->id, $said->date, $now);

        if ($said->time !== null) {
            $from = new DateTimeImmutable("{$said->date} {$said->time}", $zone);
            $to = $from;
        } elseif ($said->dayPart !== null) {
            $from = new DateTimeImmutable("{$said->date} {$said->dayPart->startsAt()}:00", $zone);
            $to = new DateTimeImmutable("{$said->date} {$said->dayPart->endsAt()}:59", $zone);
        } else {
            return $this->result($said, $missing, ProposalStatus::Incomplete, ProposalField::Time, $service, $collaborator);
        }

        $fitting = array_values(array_filter($free, fn (SlotAlternative $slot): bool => $slot->interval->start >= $from && $slot->interval->start <= $to));

        if ($fitting === []) {
            return $this->result($said, $missing, ProposalStatus::Unavailable, ProposalField::Time, $service, $collaborator,
                alternatives: $this->availability->closest($service->id, $collaborator?->id, $from, $now));
        }

        if ($collaborator === null) {
            $freeIds = array_map(fn (SlotAlternative $slot): string => $slot->collaboratorId->value, $fitting);
            $freeStaff = array_values(array_filter($staff, fn (ProposableCollaborator $member): bool => in_array($member->id, $freeIds, true)));

            return $this->result($said, $missing, ProposalStatus::Ambiguous, ProposalField::Collaborator, $service, candidates: $this->candidates($freeStaff), spaces: $fitting);
        }

        if ($said->time !== null) {
            return $this->result($said, $missing, ProposalStatus::Valid, null, $service, $collaborator, startsAt: $fitting[0]->interval->start, endsAt: $fitting[0]->interval->end);
        }

        return $this->result($said, $missing, ProposalStatus::Valid, null, $service, $collaborator, spaces: $fitting);
    }

    /**
     * Name by identifier.
     *
     * @param  list<ProposableService|ProposableCollaborator>  $items
     * @return array<string, string>
     */
    private function names(array $items): array
    {
        $names = [];

        foreach ($items as $item) {
            $names[$item->id] = $item->name;
        }

        return $names;
    }

    /**
     * The barbers the person can choose among.
     *
     * @param  list<ProposableCollaborator>  $members
     * @return list<ProposalCandidate>
     */
    private function candidates(array $members): array
    {
        return array_map(fn (ProposableCollaborator $member): ProposalCandidate => new ProposalCandidate($member->id, $member->name), $members);
    }

    /**
     * Build the proposal with what the person said and what the catalog resolved.
     *
     * @param  list<ProposalField>  $missing
     * @param  list<ProposalCandidate>  $candidates
     * @param  list<SlotAlternative>  $spaces
     * @param  list<SlotAlternative>  $alternatives
     */
    private function result(
        ExtractedReservation $said,
        array $missing,
        ProposalStatus $status,
        ?ProposalField $field,
        ?ProposableService $service = null,
        ?ProposableCollaborator $collaborator = null,
        array $candidates = [],
        array $spaces = [],
        array $alternatives = [],
        ?DateTimeImmutable $startsAt = null,
        ?DateTimeImmutable $endsAt = null,
    ): ReservationProposal {
        return new ReservationProposal(
            $said->service, $said->collaborator, $said->date, $said->time, $said->dayPart, $missing,
            $status, $field, $service?->id, $collaborator?->id, $startsAt, $endsAt, $service?->price, $candidates, $spaces, $alternatives,
        );
    }
}
