<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Application\UseCases;

use Src\Catalog\Invitations\Application\Contracts\BarbershopScope;
use Src\Catalog\Invitations\Application\Contracts\CollaboratorProfiles;
use Src\Catalog\Invitations\Application\Contracts\InvitationLocator;
use Src\Catalog\Invitations\Application\Contracts\InvitedAccounts;
use Src\Catalog\Invitations\Application\Contracts\InvitedMemberships;
use Src\Catalog\Invitations\Application\DTOs\AcceptInvitationCommand;
use Src\Catalog\Invitations\Domain\Contracts\InvitationRepository;
use Src\Catalog\Invitations\Domain\Exceptions\AccountDataRequiredException;
use Src\Catalog\Invitations\Domain\Exceptions\InvalidInvitationLinkException;
use Src\Catalog\Invitations\Domain\ValueObjects\InvitationToken;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Domain\Contracts\TransactionRunner;

final readonly class AcceptInvitation
{
    /** Receive the locator of the link, the scope of a barbershop, the repository, the ports toward the accounts, the roles and the profiles, the context, the clock and the transaction. */
    public function __construct(
        private InvitationLocator $locator,
        private BarbershopScope $scope,
        private InvitationRepository $invitations,
        private InvitedAccounts $accounts,
        private InvitedMemberships $memberships,
        private CollaboratorProfiles $profiles,
        private BarbershopContext $context,
        private Clock $clock,
        private TransactionRunner $transaction,
    ) {}

    /**
     * Accept the invitation with its link (RF-06, UP-04). The link names its barbershop, which is where the rest runs, as when a notice of a
     * gateway names its payment: the barbershop never comes from what the sender wrote. The link is used once; the account (new, with the
     * password the person chose under SE-07, or the existing one, whose password does not change), the role of collaborator and the profile
     * with the invited services are made together or not at all, so a weak password, or an email that already has a role in the barbershop
     * (UP-07), leaves the link as it was.
     *
     * @throws InvalidInvitationLinkException
     * @throws AccountDataRequiredException
     */
    public function execute(AcceptInvitationCommand $command): void
    {
        $hash = InvitationToken::fromPlain($command->token)->hash();
        $barbershopId = $this->locator->barbershopOf($hash) ?? throw InvalidInvitationLinkException::create();

        $this->scope->run($barbershopId, fn () => $this->transaction->run(function () use ($hash, $command): void {
            $invitation = $this->invitations->consume($hash, $this->clock->now()) ?? throw InvalidInvitationLinkException::create();
            $userId = $this->accounts->idOfEmail($invitation->email);

            if ($userId === null) {
                $userId = $this->accounts->register(
                    $command->name ?? throw AccountDataRequiredException::name(),
                    $invitation->email,
                    $command->password ?? throw AccountDataRequiredException::password(),
                );
            }

            $this->memberships->grantCollaboratorRole($this->context->current(), $userId);
            $this->profiles->create($userId, $invitation->serviceIds);
        }));
    }
}
