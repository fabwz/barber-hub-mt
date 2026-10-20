<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Domain\ValueObjects;

enum Role: string
{
    case Owner = 'owner';
    case Collaborator = 'collaborator';
    case Customer = 'customer';

    /** Tell whether the role belongs to the staff of a barbershop. */
    public function isStaff(): bool
    {
        return $this !== self::Customer;
    }
}
