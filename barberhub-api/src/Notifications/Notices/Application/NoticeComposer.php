<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application;

use Src\Notifications\Notices\Application\Contracts\BarbershopBranding;
use Src\Notifications\Notices\Application\Contracts\CatalogNames;
use Src\Notifications\Notices\Application\Contracts\Recipients;
use Src\Notifications\Notices\Application\DTOs\Notice;
use Src\Notifications\Notices\Application\DTOs\NoticeCommand;

/** Builds a notice from its facts, adding the person, the barbershop and the names; there is none when the customer has no email (CL-06). */
final readonly class NoticeComposer
{
    public function __construct(
        private Recipients $recipients,
        private BarbershopBranding $branding,
        private CatalogNames $names,
    ) {}

    /** The notice of the command, or null when nobody can receive it. */
    public function compose(NoticeCommand $command): ?Notice
    {
        $recipient = $this->recipients->ofMembership($command->customerMembershipId);

        if ($recipient === null) {
            return null;
        }

        return new Notice(
            $command->type,
            $recipient,
            $this->branding->current(),
            $this->names->serviceName($command->serviceId),
            $this->names->collaboratorName($command->collaboratorId),
            $command->startsAt,
            $command->endsAt,
            $command->expiresAt,
            $command->previousStartsAt,
            $command->reminder,
            $command->idempotencyKey,
        );
    }
}
