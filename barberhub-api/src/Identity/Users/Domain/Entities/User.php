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
        public bool $platformOperator = false,
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

    /**
     * Register an active account for a person who accepted the invitation of a barbershop (RF-06): they chose their own password, so it is
     * not temporary. Like the account of the staff it has no phone and no acceptances.
     */
    public static function createInvited(UserId $id, UserName $name, Email $email, string $passwordHash): self
    {
        return new self($id, $name, $email, null, $passwordHash, true, null, null, false);
    }

    /**
     * Register an active account the platform operator creates for the owner of a new barbershop (RF-01, UP-05), with a temporary password
     * that must be changed at the first login (UP-04). It has no acceptances: the person gives them at that login.
     */
    public static function createOwner(UserId $id, UserName $name, Email $email, Phone $phone, string $passwordHash): self
    {
        return new self($id, $name, $email, $phone, $passwordHash, true, null, null, true);
    }

    /**
     * Register an active account that is a platform operator (RF-55, PL-01) with a temporary password that must be changed at the first
     * login (RF-40). It has no phone and no acceptances, and it never holds a role in a barbershop.
     */
    public static function createPlatformOperator(UserId $id, UserName $name, Email $email, string $passwordHash): self
    {
        return new self($id, $name, $email, null, $passwordHash, true, null, null, true, true);
    }

    /** Replace the password with a new hash; the password is no longer temporary (UP-04). */
    public function changePassword(string $passwordHash): self
    {
        return new self($this->id, $this->name, $this->email, $this->phone, $passwordHash, $this->active, $this->termsAcceptedAt, $this->privacyAcceptedAt, false, $this->platformOperator);
    }

    /** Correct the name, the phone and the email; the password, the acceptances and the temporary state stay as they are (RF-45). */
    public function updateProfile(UserName $name, Email $email, Phone $phone): self
    {
        return new self($this->id, $name, $email, $phone, $this->passwordHash, $this->active, $this->termsAcceptedAt, $this->privacyAcceptedAt, $this->mustChangePassword, $this->platformOperator);
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
        bool $platformOperator = false,
    ): self {
        return new self($id, $name, $email, $phone, $passwordHash, $active, $termsAcceptedAt, $privacyAcceptedAt, $mustChangePassword, $platformOperator);
    }
}
