<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Catalog\Barbershops\Application\Contracts\BarbershopDirectoryReader;
use Src\Catalog\Barbershops\Application\Contracts\BarbershopScope;
use Src\Catalog\Barbershops\Application\Contracts\BarbershopSummaryReader;
use Src\Catalog\Barbershops\Application\Contracts\CollaboratorProfiles;
use Src\Catalog\Barbershops\Application\Contracts\OwnerAccounts;
use Src\Catalog\Barbershops\Application\Contracts\OwnerMemberships;
use Src\Catalog\Barbershops\Application\Contracts\PersonMemberships;
use Src\Catalog\Barbershops\Application\Contracts\PublicBarbershopReader;
use Src\Catalog\Barbershops\Application\Contracts\RentInstallments;
use Src\Catalog\Barbershops\Domain\Contracts\BarbershopRepository;
use Src\Catalog\Barbershops\Infrastructure\Collaborators\CollaboratorsCollaboratorProfiles;
use Src\Catalog\Barbershops\Infrastructure\Identity\IdentityOwnerAccounts;
use Src\Catalog\Barbershops\Infrastructure\Identity\IdentityOwnerMemberships;
use Src\Catalog\Barbershops\Infrastructure\Identity\IdentityPersonMemberships;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Queries\EloquentBarbershopDirectoryReader;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Queries\EloquentBarbershopSummaryReader;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Queries\EloquentPublicBarbershopReader;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Repositories\EloquentBarbershopRepository;
use Src\Catalog\Barbershops\Infrastructure\Rent\RentModuleInstallments;
use Src\Catalog\Barbershops\Infrastructure\Tenancy\ProcessBarbershopScope;

final class BarbershopsServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        BarbershopRepository::class => EloquentBarbershopRepository::class,
        OwnerAccounts::class => IdentityOwnerAccounts::class,
        OwnerMemberships::class => IdentityOwnerMemberships::class,
        BarbershopScope::class => ProcessBarbershopScope::class,
        PublicBarbershopReader::class => EloquentPublicBarbershopReader::class,
        PersonMemberships::class => IdentityPersonMemberships::class,
        BarbershopSummaryReader::class => EloquentBarbershopSummaryReader::class,
        BarbershopDirectoryReader::class => EloquentBarbershopDirectoryReader::class,
        CollaboratorProfiles::class => CollaboratorsCollaboratorProfiles::class,
        RentInstallments::class => RentModuleInstallments::class,
    ];
}
