<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

use DateTimeImmutable;
use Src\Reservations\Reservations\Domain\ValueObjects\SlotAlternative;

interface AnyAvailableCollaborators
{
    /**
     * The identifiers of the active collaborators who offer the service and work on the day of the start: the ones that
     * "any available" may assign. They come in no particular order; whoever locks them must do it in a fixed one.
     *
     * @return list<string>
     */
    public function candidates(string $serviceId, DateTimeImmutable $startsAt): array;

    /**
     * The collaborator DI-04 assigns to the space that starts at the moment, among those free then, or none if nobody is.
     * It reads the reservations again every time, so it is only as good as the locks held around it.
     */
    public function assign(string $serviceId, DateTimeImmutable $startsAt, DateTimeImmutable $now): ?string;

    /**
     * Up to 3 free spaces of the same service and day, of any collaborator, the closest to the requested start (RE-02).
     *
     * @return list<SlotAlternative>
     */
    public function alternatives(string $serviceId, DateTimeImmutable $requested, DateTimeImmutable $now): array;
}
