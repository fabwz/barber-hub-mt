<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Domain\Entities;

use DateTimeImmutable;
use Src\Catalog\Invitations\Domain\ValueObjects\InvitationId;
use Src\Catalog\Invitations\Domain\ValueObjects\InvitationToken;

/** The offer the owner makes to an email to join the staff with some services (RF-06). It belongs to a barbershop: the context of the repository. */
final readonly class Invitation
{
    /**
     * Hold the full state of an invitation; it is pending until it is used, cancelled (deleted) or expired.
     *
     * @param  list<string>  $serviceIds
     */
    private function __construct(
        public InvitationId $id,
        public string $email,
        public array $serviceIds,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
    ) {}

    /**
     * Make an invitation that expires after the validity of the link (UP-04). The email is kept in lower case, as the accounts keep theirs.
     *
     * @param  list<string>  $serviceIds
     */
    public static function create(InvitationId $id, string $email, array $serviceIds, DateTimeImmutable $now): self
    {
        return new self(
            $id,
            mb_strtolower(trim($email)),
            array_values(array_unique($serviceIds)),
            $now,
            $now->modify('+'.InvitationToken::TTL_DAYS.' days'),
        );
    }

    /**
     * Rebuild an invitation from its stored state.
     *
     * @param  list<string>  $serviceIds
     */
    public static function reconstitute(InvitationId $id, string $email, array $serviceIds, DateTimeImmutable $createdAt, DateTimeImmutable $expiresAt): self
    {
        return new self($id, $email, $serviceIds, $createdAt, $expiresAt);
    }
}
