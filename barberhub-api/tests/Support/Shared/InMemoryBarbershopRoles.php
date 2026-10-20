<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use Src\Shared\Domain\Contracts\BarbershopRoles;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

final class InMemoryBarbershopRoles implements BarbershopRoles
{
    /** @var array<string, string> */
    private array $roles = [];

    /** Give a person a role in a barbershop. */
    public function grant(string $userId, string $barbershopId, string $role): void
    {
        $this->roles["{$userId}|{$barbershopId}"] = $role;
    }

    /** Find the role the person was given in the barbershop. */
    public function roleOf(UserId $userId, BarbershopId $barbershopId): ?string
    {
        return $this->roles["{$userId->value}|{$barbershopId->value}"] ?? null;
    }
}
