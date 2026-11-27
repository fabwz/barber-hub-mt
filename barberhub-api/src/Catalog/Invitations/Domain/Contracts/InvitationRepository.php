<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Domain\Contracts;

use DateTimeImmutable;
use Src\Catalog\Invitations\Domain\Entities\Invitation;
use Src\Catalog\Invitations\Domain\ValueObjects\InvitationId;

interface InvitationRepository
{
    /** Generate the identity for a new invitation. */
    public function nextId(): InvitationId;

    /**
     * Keep the invitation with the hash of its link and forget the pending ones of the same email in the barbershop, so only the last one
     * invited works (RF-06). The person who invites is the membership of the owner. The token is never stored in plain text.
     */
    public function replacePending(Invitation $invitation, string $invitedByMembershipId, string $tokenHash): void;

    /**
     * The invitations still to be accepted at `$now`: not used and not expired, the newest first.
     *
     * @return list<Invitation>
     */
    public function pending(DateTimeImmutable $now): array;

    /** Cancel a pending invitation so its link stops working; false when there is none with that identifier (one used or expired is not pending). */
    public function cancel(InvitationId $id, DateTimeImmutable $now): bool;

    /** Keep the hash of a new link for a pending invitation, over the one it had, and answer the invitation; null when it is no longer pending. */
    public function replaceLink(InvitationId $id, string $tokenHash, DateTimeImmutable $now): ?Invitation;

    /** Find the pending invitation of a link, without using it; null when the hash is unknown, was used, was cancelled or has expired. */
    public function findPendingByLink(string $tokenHash, DateTimeImmutable $now): ?Invitation;

    /**
     * Use the link once: answer the invitation and mark it used in the same statement, so two requests with the same link cannot both succeed;
     * null when the hash is unknown, was used, was cancelled or has expired at `$now`. The hashes are compared in constant time.
     */
    public function consume(string $tokenHash, DateTimeImmutable $now): ?Invitation;
}
