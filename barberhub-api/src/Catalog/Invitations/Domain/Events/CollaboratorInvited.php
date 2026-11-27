<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Domain\Events;

/**
 * The owner invited an email to join the staff (RF-06). The event carries no email and no secret: whoever listens asks for the link, away from
 * the request, and it travels with its barbershop because the process that handles it has no request to take it from.
 */
final readonly class CollaboratorInvited
{
    /** Hold the barbershop and the invitation. */
    public function __construct(public string $barbershopId, public string $invitationId) {}
}
