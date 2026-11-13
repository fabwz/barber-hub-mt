<?php

declare(strict_types=1);

namespace Tests\Support\Tenancy;

/**
 * What a route of the application is meant to demand, written down by hand so that a new route cannot be protected (or left
 * open) by accident: the guard test compares it with the middleware the route really has (RF-42, UP-02).
 */
final readonly class RouteRule
{
    /** Open on purpose, with the reason. */
    public const string PUBLIC = 'public';

    /** Needs a session and nothing more: the data are the person's own. */
    public const string AUTHENTICATED = 'authenticated';

    /** Needs a role in the barbershop of the URL. */
    public const string ROLE = 'role';

    /** Needs a role and the resource must also belong to the person (or, for the owner, to the barbershop). */
    public const string OWNED = 'owned';

    /** A customer operation that creates the first membership (RD-18, UP-09): a person with no role goes through. */
    public const string FIRST_USE = 'first-use';

    /**
     * @param  list<string>  $roles  The roles allowed; empty for public and authenticated routes.
     * @param  string  $note  Why it is public, or which resource must belong to whom.
     * @param  bool  $scoped  Public route that sets the barbershop of the URL ("barbershop:public").
     * @param  bool  $conditional  A route the framework or a package registers only in some environments.
     * @param  string|null  $outcomes  Profile of expected answers per actor (AccessMatrix::OUTCOMES); null for public routes.
     */
    private function __construct(
        public string $kind,
        public array $roles,
        public string $note,
        public bool $scoped = false,
        public bool $conditional = false,
        public ?string $outcomes = null,
    ) {}

    public static function public(string $reason, bool $scoped = false, bool $conditional = false): self
    {
        return new self(self::PUBLIC, [], $reason, $scoped, $conditional);
    }

    public static function authenticated(string $note): self
    {
        return new self(self::AUTHENTICATED, [], $note, outcomes: AccessMatrix::AUTHENTICATED);
    }

    /** @param  list<string>  $roles */
    public static function role(array $roles, string $outcomes, string $note = 'Por rol.'): self
    {
        return new self(self::ROLE, $roles, $note, outcomes: $outcomes);
    }

    /** @param  list<string>  $roles */
    public static function owned(array $roles, string $outcomes, string $note): self
    {
        return new self(self::OWNED, $roles, $note, outcomes: $outcomes);
    }

    public static function firstUse(string $outcomes, string $note): self
    {
        return new self(self::FIRST_USE, ['customer'], $note, outcomes: $outcomes);
    }
}
