<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Infrastructure;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Src\Catalog\Invitations\Application\Contracts\BarbershopScope;
use Src\Catalog\Invitations\Application\Contracts\BarbershopServices;
use Src\Catalog\Invitations\Application\Contracts\CollaboratorProfiles;
use Src\Catalog\Invitations\Application\Contracts\InvitationLocator;
use Src\Catalog\Invitations\Application\Contracts\InvitedAccounts;
use Src\Catalog\Invitations\Application\Contracts\InvitedMemberships;
use Src\Catalog\Invitations\Application\Contracts\InvitingBarbershop;
use Src\Catalog\Invitations\Domain\Contracts\InvitationRepository;
use Src\Catalog\Invitations\Infrastructure\Barbershops\BarbershopsInvitingBarbershop;
use Src\Catalog\Invitations\Infrastructure\Collaborators\CollaboratorsCollaboratorProfiles;
use Src\Catalog\Invitations\Infrastructure\Identity\IdentityInvitedAccounts;
use Src\Catalog\Invitations\Infrastructure\Identity\IdentityInvitedMemberships;
use Src\Catalog\Invitations\Infrastructure\Persistence\Repositories\EloquentInvitationRepository;
use Src\Catalog\Invitations\Infrastructure\Services\ServicesBarbershopServices;
use Src\Catalog\Invitations\Infrastructure\Tenancy\DatabaseInvitationLocator;
use Src\Catalog\Invitations\Infrastructure\Tenancy\ProcessBarbershopScope;

final class InvitationsServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        InvitationRepository::class => EloquentInvitationRepository::class,
        InvitationLocator::class => DatabaseInvitationLocator::class,
        BarbershopScope::class => ProcessBarbershopScope::class,
        InvitedAccounts::class => IdentityInvitedAccounts::class,
        InvitedMemberships::class => IdentityInvitedMemberships::class,
        CollaboratorProfiles::class => CollaboratorsCollaboratorProfiles::class,
        BarbershopServices::class => ServicesBarbershopServices::class,
        InvitingBarbershop::class => BarbershopsInvitingBarbershop::class,
    ];

    /** Limit the public routes of the links per address: the token is long and random, but nobody should be able to flood the lookup. */
    public function boot(): void
    {
        RateLimiter::for('invitation-links', fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->ip()));
    }
}
