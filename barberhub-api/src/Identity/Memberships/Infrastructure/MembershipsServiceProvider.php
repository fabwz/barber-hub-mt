<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Identity\Memberships\Domain\Contracts\MembershipRepository;
use Src\Identity\Memberships\Infrastructure\Persistence\Repositories\EloquentBarbershopRoles;
use Src\Identity\Memberships\Infrastructure\Persistence\Repositories\EloquentMembershipRepository;
use Src\Shared\Domain\Contracts\BarbershopRoles;

final class MembershipsServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        MembershipRepository::class => EloquentMembershipRepository::class,
        BarbershopRoles::class => EloquentBarbershopRoles::class,
    ];
}
