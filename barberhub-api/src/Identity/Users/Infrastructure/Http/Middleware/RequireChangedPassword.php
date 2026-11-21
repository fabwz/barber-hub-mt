<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Src\Identity\Users\Domain\Exceptions\TemporaryPasswordMustBeChangedException;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireChangedPassword
{
    /** Routes a person with a temporary password may still use: changing it and closing the session. */
    private const array ALLOWED_ROUTES = [
        'identity.password.update',
        'identity.tokens.destroy-current',
        'identity.tokens.destroy-all',
    ];

    /**
     * Refuse, with 403 "contrasena-temporal", every route that asks for a session when the account still has the temporary
     * password it was given (UP-04, RF-40). It belongs to the "api" group so no route can forget it; it resolves the token
     * by itself, so it does not depend on its place before "auth:sanctum". Public routes and requests without a valid
     * token go through: the first need no session and the second are answered 401 by the authentication.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route === null || ! in_array('auth:sanctum', $route->gatherMiddleware(), true) || in_array($route->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        $user = $request->user('sanctum');

        // A token always resolves the full row; a model without the column (a session faked in a test) has no temporary password.
        if ($user instanceof UserModel && ($user->getAttributes()['must_change_password'] ?? false)) {
            throw TemporaryPasswordMustBeChangedException::create();
        }

        return $next($request);
    }
}
