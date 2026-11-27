<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Application\UseCases;

use Src\Catalog\Invitations\Application\Contracts\BarbershopServices;
use Src\Catalog\Invitations\Application\Contracts\InvitedMemberships;
use Src\Catalog\Invitations\Application\DTOs\InviteCollaboratorCommand;
use Src\Catalog\Invitations\Domain\Contracts\InvitationRepository;
use Src\Catalog\Invitations\Domain\Entities\Invitation;
use Src\Catalog\Invitations\Domain\Events\CollaboratorInvited;
use Src\Catalog\Invitations\Domain\Exceptions\UnknownServiceException;
use Src\Catalog\Invitations\Domain\ValueObjects\InvitationToken;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Domain\Contracts\DomainEvents;
use Src\Shared\Domain\Contracts\TransactionRunner;

final readonly class InviteCollaborator
{
    /** Receive the invitation repository, the ports toward the services and the roles, the context, the clock, the events and the transaction. */
    public function __construct(
        private InvitationRepository $invitations,
        private BarbershopServices $services,
        private InvitedMemberships $memberships,
        private BarbershopContext $context,
        private Clock $clock,
        private DomainEvents $events,
        private TransactionRunner $transaction,
    ) {}

    /**
     * Invite an email to join the staff with some services (RF-06). Inviting an email again replaces the pending invitation. The email is
     * written away from the request, by whoever listens to the event: the link is born there and the token is never stored in plain text
     * or put in the queue. Until then the invitation holds the hash of a token nobody has, so it cannot be used.
     *
     * @throws UnknownServiceException
     */
    public function execute(InviteCollaboratorCommand $command): Invitation
    {
        $existing = $this->services->existingAmong($command->serviceIds);

        foreach ($command->serviceIds as $index => $serviceId) {
            if (! in_array($serviceId, $existing, true)) {
                throw UnknownServiceException::at($index);
            }
        }

        $barbershopId = $this->context->current();
        $inviter = $this->memberships->membershipIdOf($barbershopId, $command->invitedByUserId);
        $invitation = Invitation::create($this->invitations->nextId(), $command->email, $command->serviceIds, $this->clock->now());

        return $this->transaction->run(function () use ($invitation, $inviter, $barbershopId): Invitation {
            $this->invitations->replacePending($invitation, (string) $inviter, InvitationToken::generate()->hash());
            $this->events->publish(new CollaboratorInvited($barbershopId->value, $invitation->id->value));

            return $invitation;
        });
    }
}
