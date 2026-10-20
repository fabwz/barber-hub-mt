<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Catalog\Collaborators\Application\Contracts\BarbershopSchedule;
use Src\Catalog\Collaborators\Application\Contracts\BarbershopServices;
use Src\Catalog\Collaborators\Application\Contracts\StaffAccounts;
use Src\Catalog\Collaborators\Application\Contracts\StaffMemberships;
use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Catalog\Collaborators\Infrastructure\Identity\IdentityStaffAccounts;
use Src\Catalog\Collaborators\Infrastructure\Identity\IdentityStaffMemberships;
use Src\Catalog\Collaborators\Infrastructure\OpeningHours\OpeningHoursBarbershopSchedule;
use Src\Catalog\Collaborators\Infrastructure\Persistence\Repositories\EloquentCollaboratorRepository;
use Src\Catalog\Collaborators\Infrastructure\Services\ServicesBarbershopServices;

final class CollaboratorsServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        CollaboratorRepository::class => EloquentCollaboratorRepository::class,
        StaffAccounts::class => IdentityStaffAccounts::class,
        StaffMemberships::class => IdentityStaffMemberships::class,
        BarbershopSchedule::class => OpeningHoursBarbershopSchedule::class,
        BarbershopServices::class => ServicesBarbershopServices::class,
    ];
}
