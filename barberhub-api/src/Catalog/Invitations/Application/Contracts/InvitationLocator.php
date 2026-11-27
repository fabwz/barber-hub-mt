<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Application\Contracts;

/** Where the link of an invitation says it belongs, for the routes that have no session and so no barbershop yet (docs/database.md). */
interface InvitationLocator
{
    /**
     * The barbershop of the invitation with that hash of a link, or null when there is none. It only says where to look: whether the link
     * still works is decided afterwards, inside that barbershop.
     */
    public function barbershopOf(string $tokenHash): ?string;
}
