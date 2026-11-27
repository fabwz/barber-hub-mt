<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Application\UseCases;

use Src\Catalog\Invitations\Domain\Contracts\InvitationRepository;
use Src\Catalog\Invitations\Domain\Entities\Invitation;
use Src\Shared\Domain\Contracts\Clock;

final readonly class ListPendingInvitations
{
    /** Receive the invitation repository and the clock. */
    public function __construct(private InvitationRepository $invitations, private Clock $clock) {}

    /**
     * The invitations of the barbershop that nobody has accepted and that have not expired, the newest first (RF-06).
     *
     * @return list<Invitation>
     */
    public function execute(): array
    {
        return $this->invitations->pending($this->clock->now());
    }
}
