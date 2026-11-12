<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

/**
 * What a phrase says about a reservation, as the person said it and not yet matched against the catalog (IA-02). Each field
 * is null when the phrase does not mention it.
 */
final readonly class ExtractedReservation
{
    /**
     * @param  ?string  $service  The service as the person named it, for example "corte y barba".
     * @param  ?string  $collaborator  The barber as the person named them, for example "Kevin".
     * @param  ?string  $date  The day as `YYYY-MM-DD`, with relative days ("el sábado") already resolved against today.
     * @param  ?string  $time  The time as `HH:MM`, 24 hours.
     */
    public function __construct(
        public ?string $service = null,
        public ?string $collaborator = null,
        public ?string $date = null,
        public ?string $time = null,
        public ?ExtractedDayPart $dayPart = null,
    ) {}
}
