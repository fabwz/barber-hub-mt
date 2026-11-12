<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

/**
 * A piece of a reservation. A phrase may leave out the service, the date or the time and the person must still give them; the
 * barber is optional (IA-01), so it is never missing, but it can fail to be recognized or be ambiguous.
 */
enum ProposalField: string
{
    case Service = 'service';
    case Collaborator = 'collaborator';
    case Date = 'date';
    case Time = 'time';
}
