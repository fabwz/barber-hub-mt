<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Application\UseCases;

use Src\Catalog\Invitations\Domain\Contracts\InvitationRepository;
use Src\Catalog\Invitations\Domain\Exceptions\InvitationNotFoundException;
use Src\Catalog\Invitations\Domain\ValueObjects\InvitationId;
use Src\Shared\Domain\Contracts\Clock;

final readonly class CancelInvitation
{
    /** Receive the invitation repository and the clock. */
    public function __construct(private InvitationRepository $invitations, private Clock $clock) {}

    /**
     * Cancel a pending invitation: its link stops working at once (RF-06). One that does not exist in the barbershop, was accepted or
     * has expired reads as unknown (UP-06).
     *
     * @throws InvitationNotFoundException
     */
    public function execute(string $invitationId): void
    {
        if (! $this->invitations->cancel(new InvitationId($invitationId), $this->clock->now())) {
            throw InvitationNotFoundException::create();
        }
    }
}
