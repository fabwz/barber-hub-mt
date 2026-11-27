<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Application\UseCases;

use SensitiveParameter;
use Src\Catalog\Invitations\Application\Contracts\BarbershopScope;
use Src\Catalog\Invitations\Application\Contracts\InvitationLocator;
use Src\Catalog\Invitations\Application\Contracts\InvitedAccounts;
use Src\Catalog\Invitations\Application\Contracts\InvitingBarbershop;
use Src\Catalog\Invitations\Application\DTOs\InvitationDetails;
use Src\Catalog\Invitations\Domain\Contracts\InvitationRepository;
use Src\Catalog\Invitations\Domain\Exceptions\InvalidInvitationLinkException;
use Src\Catalog\Invitations\Domain\ValueObjects\InvitationToken;
use Src\Shared\Domain\Contracts\Clock;

final readonly class DescribeInvitation
{
    /** Receive the locator of the link, the scope of a barbershop, the repository, the ports toward the accounts and the barbershop, and the clock. */
    public function __construct(
        private InvitationLocator $locator,
        private BarbershopScope $scope,
        private InvitationRepository $invitations,
        private InvitedAccounts $accounts,
        private InvitingBarbershop $barbershop,
        private Clock $clock,
    ) {}

    /**
     * Tell the person who opened a link what they are being invited to, without using the link, so the screen asks only for what is missing:
     * a name and a password when the email has no account, nothing more when it has one (RF-06). Only whoever holds the link can see it.
     *
     * @throws InvalidInvitationLinkException
     */
    public function execute(#[SensitiveParameter] string $token): InvitationDetails
    {
        $hash = InvitationToken::fromPlain($token)->hash();
        $barbershopId = $this->locator->barbershopOf($hash) ?? throw InvalidInvitationLinkException::create();

        return $this->scope->run($barbershopId, function () use ($hash): InvitationDetails {
            $invitation = $this->invitations->findPendingByLink($hash, $this->clock->now()) ?? throw InvalidInvitationLinkException::create();

            return new InvitationDetails($this->barbershop->name(), $invitation->email, $this->accounts->idOfEmail($invitation->email) !== null);
        });
    }
}
