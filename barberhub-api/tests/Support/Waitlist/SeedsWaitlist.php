<?php

declare(strict_types=1);

namespace Tests\Support\Waitlist;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rows of the waitlist written straight into the table, as the tests of the schema, of the isolation and of the use cases need
 * them. It goes with SeedsBookableBarbershops and SeedsReservations, whose helpers it uses: the services, the collaborators and
 * the customers a row points at. Every insert runs as the barbershop the queries of the test are scoped to.
 */
trait SeedsWaitlist
{
    /** Insert a row of the waitlist and return its identifier. A null collaborator means "any". */
    private function insertWaitlistEntry(
        string $barbershopId,
        string $membershipId,
        string $serviceId,
        ?string $collaboratorId = null,
        string $date = '2030-03-05',
        string $status = 'waiting',
        string $joinedAt = '2030-03-01 08:00:00-06',
    ): string {
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('waitlist_entries')->insert([
            'id' => $id,
            'barbershop_id' => $barbershopId,
            'customer_membership_id' => $membershipId,
            'service_id' => $serviceId,
            'collaborator_id' => $collaboratorId,
            'desired_date' => $date,
            'status' => $status,
            'joined_at' => $joinedAt,
        ]);

        return $id;
    }
}
