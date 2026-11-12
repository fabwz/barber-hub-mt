<?php

declare(strict_types=1);

namespace Tests\Support\Reservations;

use Src\Reservations\Reservations\Application\Contracts\ReservationExtractor;
use Src\Reservations\Reservations\Application\DTOs\ExtractedReservation;
use Src\Reservations\Reservations\Application\DTOs\ExtractionContext;
use Throwable;

/** A reservation extractor that answers what the test queued, one outcome per call, and counts the calls that reach it. */
final class ScriptedReservationExtractor implements ReservationExtractor
{
    public int $calls = 0;

    /** @var list<ExtractedReservation|Throwable> */
    private array $outcomes = [];

    /** What to do from now on: the next calls, in order, answer or fail as told; the last outcome repeats. */
    public function will(ExtractedReservation|Throwable ...$outcomes): void
    {
        $this->outcomes = array_values($outcomes);
    }

    public function extract(string $phrase, ExtractionContext $context): ExtractedReservation
    {
        $this->calls++;
        $outcome = count($this->outcomes) > 1 ? array_shift($this->outcomes) : ($this->outcomes[0] ?? new ExtractedReservation);

        return $outcome instanceof Throwable ? throw $outcome : $outcome;
    }
}
