<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Identity\Users\Domain\Contracts\LoginAttempts;
use Src\Identity\Users\Domain\Contracts\PasswordHasher;
use Src\Identity\Users\Domain\Contracts\PasswordResetLimiter;
use Src\Identity\Users\Domain\Contracts\PasswordResetTokens;
use Src\Identity\Users\Domain\Contracts\TokenIssuer;
use Src\Identity\Users\Domain\Contracts\TokenRevoker;
use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Infrastructure\Console\CreatePlatformOperatorCommand;
use Src\Identity\Users\Infrastructure\Persistence\Repositories\EloquentPasswordResetTokens;
use Src\Identity\Users\Infrastructure\Persistence\Repositories\EloquentPlatformOperators;
use Src\Identity\Users\Infrastructure\Persistence\Repositories\EloquentUserRepository;
use Src\Identity\Users\Infrastructure\Security\BcryptPasswordHasher;
use Src\Identity\Users\Infrastructure\Security\RateLimiterLoginAttempts;
use Src\Identity\Users\Infrastructure\Security\RateLimiterPasswordResetLimiter;
use Src\Identity\Users\Infrastructure\Security\SanctumTokenIssuer;
use Src\Identity\Users\Infrastructure\Security\SanctumTokenRevoker;
use Src\Shared\Domain\Contracts\PlatformOperators;

final class UsersServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        LoginAttempts::class => RateLimiterLoginAttempts::class,
        PasswordHasher::class => BcryptPasswordHasher::class,
        PasswordResetLimiter::class => RateLimiterPasswordResetLimiter::class,
        PasswordResetTokens::class => EloquentPasswordResetTokens::class,
        PlatformOperators::class => EloquentPlatformOperators::class,
        TokenIssuer::class => SanctumTokenIssuer::class,
        TokenRevoker::class => SanctumTokenRevoker::class,
        UserRepository::class => EloquentUserRepository::class,
    ];

    /** Register the command that creates the platform operator: the only way to make one (PL-01). */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CreatePlatformOperatorCommand::class]);
        }
    }
}
