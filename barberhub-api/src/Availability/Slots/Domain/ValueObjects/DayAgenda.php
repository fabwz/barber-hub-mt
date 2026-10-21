<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Shared\Domain\ValueObjects\TimeRange;

/** Everything that decides the free spaces of one collaborator on one day, already loaded (DI-01). */
final readonly class DayAgenda
{
    /**
     * @param  list<TimeRange>  $openRanges  Wall-clock ranges the barbershop is open; none when it is closed.
     * @param  list<TimeRange>  $shifts  Wall-clock ranges the collaborator works.
     * @param  list<TimeRange>  $breaks  Wall-clock ranges the collaborator rests.
     * @param  list<TimeInterval>  $blocked  Instants held by temporary or confirmed reservations, cleaning included.
     */
    public function __construct(
        public CollaboratorId $collaboratorId,
        public CalendarDate $date,
        public array $openRanges,
        public array $shifts,
        public array $breaks,
        public array $blocked,
    ) {}
}
