<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Infrastructure\Tenancy;

use Illuminate\Support\Facades\DB;
use Src\Catalog\Invitations\Application\Contracts\InvitationLocator;

/**
 * Finds the barbershop of a link through the function `locate_invitation_of_link` of the database, the only thing that can read across
 * barbershops: read-only, fixed search_path, answering just one identifier. It changes no policy and the request keeps the application
 * role (docs/database.md), the same technique the notices of the gateways use to find their payment.
 */
final readonly class DatabaseInvitationLocator implements InvitationLocator
{
    public function barbershopOf(string $tokenHash): ?string
    {
        // Inside a transaction, so the barbershop the function sets while it looks never outlives the call.
        /** @var object{barbershop: string|null} $row */
        $row = DB::transaction(fn (): object => DB::selectOne('select locate_invitation_of_link(?) as barbershop', [$tokenHash]));

        return $row->barbershop;
    }
}
