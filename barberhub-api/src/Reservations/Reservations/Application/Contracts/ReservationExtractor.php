<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

use Src\Reservations\Reservations\Application\DTOs\ExtractedReservation;
use Src\Reservations\Reservations\Application\DTOs\ExtractionContext;
use Src\Reservations\Reservations\Application\Exceptions\ExtractionInvalidException;
use Src\Reservations\Reservations\Application\Exceptions\ExtractorMisconfiguredException;
use Src\Reservations\Reservations\Application\Exceptions\ExtractorUnavailableException;

/**
 * What the system asks of the language provider behind the reservation by phrase (RF-37): turn a free phrase into structured
 * data. There is one adapter per provider in Infrastructure, chosen in config/ai.php, so changing provider touches no use case.
 *
 * The result is what the person said, not a decision: it is not matched against the catalog or the availability (IA-02), and
 * nothing is created (IA-03). Only the phrase and the context of the barbershop travel to the provider (IA-06).
 */
interface ReservationExtractor
{
    /**
     * Extract the service, the barber, the date and the time or part of the day the phrase mentions; whatever it does not mention is null.
     *
     * @throws ExtractorUnavailableException When the provider does not answer in time, cannot be reached, answers with an error or is not usable (IA-05).
     * @throws ExtractionInvalidException When the provider answers something that does not have the expected shape (IA-05).
     * @throws ExtractorMisconfiguredException When our own configuration makes the provider reject the request (no model, bad request).
     */
    public function extract(string $phrase, ExtractionContext $context): ExtractedReservation;
}
