<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\UserId;

/** Who changed a reservation: a person, or the system when a webhook or the expiry task did (RE-09). */
final readonly class Author
{
    private function __construct(public ?UserId $user) {}

    /** A change made by a person. */
    public static function person(UserId $user): self
    {
        return new self($user);
    }

    /** A change made by the system, without a person. */
    public static function system(): self
    {
        return new self(null);
    }

    /** Whether the system made the change. */
    public function isSystem(): bool
    {
        return $this->user === null;
    }
}
