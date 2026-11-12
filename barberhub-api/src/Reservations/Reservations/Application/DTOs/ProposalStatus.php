<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

/** The result of checking a proposal against the catalog and the availability of the barbershop (IA-02, DI-01). */
enum ProposalStatus: string
{
    /** Everything is resolved against the catalog and there is a free space. */
    case Valid = 'valid';

    /** The phrase leaves out a service, a date or a time. */
    case Incomplete = 'incomplete';

    /** The phrase fits several services or barbers, or the barber is not named and several are free. */
    case Ambiguous = 'ambiguous';

    /** The service or the barber named does not exist in the catalog. */
    case Unrecognized = 'unrecognized';

    /** The space asked for does not exist or is taken. */
    case Unavailable = 'unavailable';
}
