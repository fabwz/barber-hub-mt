<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Domain\Entities;

use Src\Identity\Memberships\Domain\ValueObjects\MembershipId;
use Src\Identity\Memberships\Domain\ValueObjects\Role;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class Membership
{
    /** Hold the full state of the role of a person in a barbershop. */
    private function __construct(
        public MembershipId $id,
        public UserId $userId,
        public BarbershopId $barbershopId,
        public Role $role,
        public bool $active,
    ) {}

    /** Grant an active role to a person in a barbershop (UP-01). */
    public static function create(MembershipId $id, UserId $userId, BarbershopId $barbershopId, Role $role): self
    {
        return new self($id, $userId, $barbershopId, $role, true);
    }

    /** Rebuild a membership from its stored state. */
    public static function reconstitute(
        MembershipId $id,
        UserId $userId,
        BarbershopId $barbershopId,
        Role $role,
        bool $active,
    ): self {
        return new self($id, $userId, $barbershopId, $role, $active);
    }

    /** The same role, active again. */
    public function activate(): self
    {
        return new self($this->id, $this->userId, $this->barbershopId, $this->role, true);
    }

    /** The same role, switched off: the person no longer enters the barbershop with it. */
    public function deactivate(): self
    {
        return new self($this->id, $this->userId, $this->barbershopId, $this->role, false);
    }
}
