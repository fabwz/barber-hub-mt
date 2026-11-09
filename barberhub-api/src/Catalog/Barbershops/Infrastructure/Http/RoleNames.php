<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http;

final class RoleNames
{
    /**
     * The name of each role in the API, in Spanish, as the glossary lists them.
     *
     * @var array<string, string>
     */
    private const array BY_ROLE = [
        'owner' => 'dueno',
        'collaborator' => 'colaborador',
        'customer' => 'cliente',
    ];

    /** The name of the role in the API. */
    public static function of(string $role): string
    {
        return self::BY_ROLE[$role];
    }
}
