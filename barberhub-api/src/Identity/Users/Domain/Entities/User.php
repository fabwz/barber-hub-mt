<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Entities;

use DateTimeImmutable;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Src\Shared\Domain\ValueObjects\Phone;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class User
{
    /** Hold the full state of a user account. */
    private function __construct(
        public UserId $id,
        public UserName $name,
        public Email $email,
        public ?Phone $phone,
        public string $passwordHash,
        public bool $active,
        public ?DateTimeImmutable $termsAcceptedAt,
        public ?DateTimeImmutable $privacyAcceptedAt,
        public bool $mustChangePassword,
    ) {}

    /** Register an active account whose owner accepted the terms and the privacy policy (CL-02). */
    public static function create(
        UserId $id,
        UserName $name,
        Email $email,
        Phone $phone,
        string $passwordHash,
        DateTimeImmutable $termsAcceptedAt,
        DateTimeImmutable $privacyAcceptedAt,
    ): self {
        return new self($id, $name, $email, $phone, $passwordHash, true, $termsAcceptedAt, $privacyAcceptedAt, false);
    }

    /**
     * Register an active account the owner creates for a member of the staff, with a temporary password that must be
     * changed at the first login (UP-04). It has no phone and no acceptances: the person gives them at that login.
     */
    public static function createStaff(UserId $id, UserName $name, Email $email, string $passwordHash): self
    {
        return new self($id, $name, $email, null, $passwordHash, true, null, null, true);
    }

    /** Rebuild an account from its stored state. */
    public static function reconstitute(
        UserId $id,
        UserName $name,
        Email $email,
        ?Phone $phone,
        string $passwordHash,
        bool $active,
        ?DateTimeImmutable $termsAcceptedAt,
        ?DateTimeImmutable $privacyAcceptedAt,
        bool $mustChangePassword,
    ): self {
        return new self($id, $name, $email, $phone, $passwordHash, $active, $termsAcceptedAt, $privacyAcceptedAt, $mustChangePassword);
    }
}
