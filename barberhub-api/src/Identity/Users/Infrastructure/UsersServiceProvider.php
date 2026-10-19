<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Identity\Users\Domain\Contracts\PasswordHasher;
use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Infrastructure\Persistence\Repositories\EloquentUserRepository;
use Src\Identity\Users\Infrastructure\Security\BcryptPasswordHasher;

final class UsersServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        PasswordHasher::class => BcryptPasswordHasher::class,
        UserRepository::class => EloquentUserRepository::class,
    ];
}
