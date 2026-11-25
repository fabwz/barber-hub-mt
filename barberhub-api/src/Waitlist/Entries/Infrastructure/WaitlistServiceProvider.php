<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Waitlist\Entries\Application\Contracts\CollaboratorNames;
use Src\Waitlist\Entries\Application\Contracts\CustomerMemberships;
use Src\Waitlist\Entries\Application\Contracts\OpenSpaces;
use Src\Waitlist\Entries\Application\Contracts\WaitlistCatalog;
use Src\Waitlist\Entries\Application\Contracts\WaitlistSettings;
use Src\Waitlist\Entries\Domain\Contracts\WaitlistEntryRepository;
use Src\Waitlist\Entries\Infrastructure\Availability\AvailabilityOpenSpaces;
use Src\Waitlist\Entries\Infrastructure\Barbershops\BarbershopsWaitlistSettings;
use Src\Waitlist\Entries\Infrastructure\Catalog\CatalogWaitlistCatalog;
use Src\Waitlist\Entries\Infrastructure\Collaborators\CollaboratorsCollaboratorNames;
use Src\Waitlist\Entries\Infrastructure\Memberships\MembershipsCustomerMemberships;
use Src\Waitlist\Entries\Infrastructure\Persistence\Repositories\EloquentWaitlistEntryRepository;

final class WaitlistServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        WaitlistEntryRepository::class => EloquentWaitlistEntryRepository::class,
        WaitlistSettings::class => BarbershopsWaitlistSettings::class,
        WaitlistCatalog::class => CatalogWaitlistCatalog::class,
        CollaboratorNames::class => CollaboratorsCollaboratorNames::class,
        OpenSpaces::class => AvailabilityOpenSpaces::class,
        CustomerMemberships::class => MembershipsCustomerMemberships::class,
    ];
}
