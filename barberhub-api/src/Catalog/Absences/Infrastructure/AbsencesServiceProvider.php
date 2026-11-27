<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Catalog\Absences\Application\Contracts\AbsenceCollaborators;
use Src\Catalog\Absences\Application\Contracts\ReservationConflicts;
use Src\Catalog\Absences\Domain\Contracts\AbsenceRepository;
use Src\Catalog\Absences\Infrastructure\Collaborators\CollaboratorsAbsenceCollaborators;
use Src\Catalog\Absences\Infrastructure\Persistence\Repositories\EloquentAbsenceRepository;
use Src\Catalog\Absences\Infrastructure\Reservations\ReservationsReservationConflicts;

final class AbsencesServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        AbsenceRepository::class => EloquentAbsenceRepository::class,
        AbsenceCollaborators::class => CollaboratorsAbsenceCollaborators::class,
        ReservationConflicts::class => ReservationsReservationConflicts::class,
    ];
}
