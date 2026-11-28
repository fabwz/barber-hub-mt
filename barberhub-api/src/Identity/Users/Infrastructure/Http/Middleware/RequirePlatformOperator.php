<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Src\Shared\Domain\Contracts\PlatformOperators;
use Src\Shared\Domain\ValueObjects\UserId;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class RequirePlatformOperator
{
    /** Receive the port that tells whether an account is a platform operator. */
    public function __construct(private PlatformOperators $operators) {}

    /**
     * Let the request through only when the authenticated account is a platform operator (RF-55, PL-01); an owner, a collaborator or a
     * customer, like any other account, is refused with 403 "prohibido". It goes after "auth:sanctum" and reads the account every time:
     * the token carries no permissions.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->operators->isOperator(new UserId((string) $user->getAuthIdentifier()))) {
            throw new AccessDeniedHttpException;
        }

        return $next($request);
    }
}
