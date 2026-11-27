<?php

declare(strict_types=1);

namespace Src\Reservations\AssistedClients\Domain\Entities;

use Src\Reservations\AssistedClients\Domain\ValueObjects\AssistedClientEmail;
use Src\Reservations\AssistedClients\Domain\ValueObjects\AssistedClientId;
use Src\Reservations\AssistedClients\Domain\ValueObjects\AssistedClientName;
use Src\Shared\Domain\ValueObjects\Phone;

/**
 * A person the owner registers for an assisted reservation, with no account (RF-12, CL-06). It lives only in the barbershop that created it, and
 * without an email it receives no notices. It is not a user and not a membership.
 */
final readonly class AssistedClient
{
    /** Hold the full state of an assisted client. */
    private function __construct(
        public AssistedClientId $id,
        public AssistedClientName $name,
        public Phone $phone,
        public ?AssistedClientEmail $email,
    ) {}

    /** Register a person; the name and the phone are required and the email is optional (CL-06). */
    public static function create(AssistedClientId $id, AssistedClientName $name, Phone $phone, ?AssistedClientEmail $email): self
    {
        return new self($id, $name, $phone, $email);
    }

    /** Rebuild an assisted client from its stored state. */
    public static function reconstitute(AssistedClientId $id, AssistedClientName $name, Phone $phone, ?AssistedClientEmail $email): self
    {
        return new self($id, $name, $phone, $email);
    }
}
