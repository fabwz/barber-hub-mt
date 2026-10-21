<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Tenancy;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Src\Shared\Domain\Contracts\BarbershopRoles;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ScopeRequestToBarbershop
{
    public const string FROM_MEMBERSHIP = 'member';

    public const string FROM_ROUTE = 'public';

    public const string FOR_CUSTOMER = 'customer';

    /** Receive the transaction that reaches the rows of one barbershop and the port that tells the role of a person in it. */
    public function __construct(private BarbershopTransaction $transaction, private BarbershopRoles $roles) {}

    /**
     * Run the request in one transaction scoped to the barbershop of the URL (RD-18). By default ("member") the person
     * must hold an active role in it, which is checked before the barbershop is set; without one, or when the barbershop
     * does not exist, the answer is the same 404 of a missing resource (UP-06, MT-03). "public" is for routes without a
     * session and sets the barbershop of the URL without checking anything. "customer" is the first exception of RD-18,
     * for the operations that create the first membership: a person without a role goes through (the operation creates
     * the membership in its own transaction, UP-09), a customer too, and an owner or collaborator of the barbershop is
     * refused with 403 (UP-07). The AI proposal and the reads of the person's own data are not modes yet: #57 adds them.
     */
    public function handle(Request $request, Closure $next, string $mode = self::FROM_MEMBERSHIP): Response
    {
        $barbershopId = $this->barbershopOf($request);
        $role = match ($mode) {
            self::FROM_MEMBERSHIP => $this->roleOf($request, $barbershopId),
            self::FROM_ROUTE => null,
            self::FOR_CUSTOMER => $this->roleOfCustomer($request, $barbershopId),
            default => throw new InvalidArgumentException("Unknown mode of the barbershop: {$mode}."),
        };

        $request->attributes->set(RequestBarbershopContext::BARBERSHOP_ATTRIBUTE, $barbershopId->value);
        $request->attributes->set(RequestBarbershopContext::ROLE_ATTRIBUTE, $role);

        try {
            return $this->transaction->run($barbershopId->value, fn (): Response => $next($request));
        } finally {
            $request->attributes->remove(RequestBarbershopContext::BARBERSHOP_ATTRIBUTE);
            $request->attributes->remove(RequestBarbershopContext::ROLE_ATTRIBUTE);
        }
    }

    /** Barbershop of the URL; an identifier that is not one is as missing as an unknown barbershop. */
    private function barbershopOf(Request $request): BarbershopId
    {
        $parameter = $request->route('barberia');

        try {
            return new BarbershopId(is_string($parameter) ? strtolower($parameter) : '');
        } catch (InvalidValueException) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * Role of the authenticated person where a customer operation creates the membership: none yet, or customer. An
     * owner or a collaborator of the barbershop cannot also be its customer, so they are refused before anything else.
     */
    private function roleOfCustomer(Request $request, BarbershopId $barbershopId): ?string
    {
        $user = $request->user() ?? throw new AuthenticationException;
        $role = $this->roles->roleOf(new UserId((string) $user->getAuthIdentifier()), $barbershopId);

        return match ($role) {
            null, 'customer' => $role,
            default => throw new AccessDeniedHttpException,
        };
    }

    /** Role of the authenticated person in the barbershop; a person without one finds nothing there. */
    private function roleOf(Request $request, BarbershopId $barbershopId): string
    {
        $user = $request->user() ?? throw new AuthenticationException;
        $userId = new UserId((string) $user->getAuthIdentifier());

        return $this->roles->roleOf($userId, $barbershopId) ?? throw new NotFoundHttpException;
    }
}
