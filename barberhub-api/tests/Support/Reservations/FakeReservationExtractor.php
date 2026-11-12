<?php

declare(strict_types=1);

namespace Tests\Support\Reservations;

use Src\Reservations\Reservations\Application\Contracts\ReservationExtractor;
use Src\Reservations\Reservations\Application\DTOs\ExtractedReservation;
use Src\Reservations\Reservations\Application\DTOs\ExtractionContext;
use Throwable;

/** A reservation extractor that answers what the test says, so a test needs no provider. It keeps what it was asked. */
final class FakeReservationExtractor implements ReservationExtractor
{
    /** @var list<array{string, ExtractionContext}> */
    public array $calls = [];

    /** Answer with the extraction, or fail with the exception. */
    public function __construct(private readonly ExtractedReservation|Throwable $outcome = new ExtractedReservation) {}

    public function extract(string $phrase, ExtractionContext $context): ExtractedReservation
    {
        $this->calls[] = [$phrase, $context];

        return $this->outcome instanceof Throwable ? throw $this->outcome : $this->outcome;
    }
}
