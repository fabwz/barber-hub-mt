<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

/** A piece of a reservation that a phrase may leave out and the person must still give. The barber is optional (IA-01), so it is never missing. */
enum ProposalField: string
{
    case Service = 'service';
    case Date = 'date';
    case Time = 'time';
}
