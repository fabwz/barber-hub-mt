<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\Contracts\ActiveCollaboratorNames;
use Src\Reservations\Reservations\Application\Contracts\ActiveServiceNames;
use Src\Reservations\Reservations\Application\Contracts\BarbershopPresence;
use Src\Reservations\Reservations\Application\Contracts\ProposableServices;
use Src\Reservations\Reservations\Application\Contracts\ReservationExtractor;
use Src\Reservations\Reservations\Application\DTOs\ExtractedReservation;
use Src\Reservations\Reservations\Application\DTOs\ExtractionContext;
use Src\Reservations\Reservations\Application\DTOs\ProposalField;
use Src\Reservations\Reservations\Application\DTOs\ReservationProposal;
use Src\Reservations\Reservations\Application\Exceptions\ExtractionInvalidException;
use Src\Reservations\Reservations\Application\Exceptions\ExtractorMisconfiguredException;
use Src\Reservations\Reservations\Application\Exceptions\ExtractorUnavailableException;
use Src\Reservations\Reservations\Application\ValidateProposal;
use Src\Reservations\Reservations\Domain\Exceptions\ReservationAssistantUnavailableException;
use Src\Reservations\Reservations\Domain\Exceptions\ReservationNotFoundException;

final readonly class ProposeReservation
{
    /** Receive the extraction port, the ports that give the names and the catalog of the barbershop, and the validation stage. */
    public function __construct(
        private BarbershopPresence $barbershop,
        private ReservationExtractor $extractor,
        private ActiveServiceNames $services,
        private ActiveCollaboratorNames $collaborators,
        private ProposableServices $catalog,
        private ValidateProposal $validate,
    ) {}

    /**
     * Turn a phrase into a proposal checked against the real barbershop (RF-37). The provider is told the phrase, today and the
     * names of the active services and collaborators of the current barbershop (IA-06), and answers only what the phrase says as
     * text. A day already gone is dropped, because it cannot be booked; an exact time wins over a part of the day. Then the
     * validation stage resolves the names against the catalog and the space against the availability (IA-02, DI-01): the
     * identifiers, the price and the free spaces are ours, never the provider's. Nothing is stored or created (IA-03).
     *
     * @throws ReservationNotFoundException When the barbershop does not exist: nothing is asked of the provider, whose free tier is limited.
     * @throws ReservationAssistantUnavailableException When the provider is unavailable, misconfigured or answers something invalid (IA-05).
     */
    public function execute(string $phrase, DateTimeImmutable $now): ReservationProposal
    {
        if (! $this->barbershop->exists()) {
            throw ReservationNotFoundException::create();
        }

        try {
            $extracted = $this->extractor->extract($phrase, new ExtractionContext($this->services->all(), $this->collaborators->all(), $now));
        } catch (ExtractorUnavailableException|ExtractionInvalidException|ExtractorMisconfiguredException) {
            throw ReservationAssistantUnavailableException::create();
        }

        $date = $extracted->date !== null && $extracted->date >= $now->format('Y-m-d') ? $extracted->date : null;
        $dayPart = $extracted->time === null ? $extracted->dayPart : null;

        $missing = [];

        if ($extracted->service === null) {
            $missing[] = ProposalField::Service;
        }

        if ($date === null) {
            $missing[] = ProposalField::Date;
        }

        if ($extracted->time === null && $dayPart === null) {
            $missing[] = ProposalField::Time;
        }

        return $this->validate->execute(
            new ExtractedReservation($extracted->service, $extracted->collaborator, $date, $extracted->time, $dayPart),
            $missing,
            $this->catalog->active(),
            $now,
        );
    }
}
