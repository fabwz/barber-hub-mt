<?php

declare(strict_types=1);

namespace Tests\Support\Vouchers;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vouchers written straight into the tables, as the tests of the schema, of the isolation and of the repositories need them.
 * It goes with SeedsBookableBarbershops and SeedsReservations, whose helpers it uses: the services, the customers and the
 * reservations a voucher points at. Every insert runs as the barbershop the queries of the test are scoped to.
 */
trait SeedsVouchers
{
    /**
     * Insert a voucher definition of a barbershop that covers the services, and return its identifier.
     *
     * @param  list<string>  $services
     */
    private function insertVoucherDefinition(string $barbershopId, array $services, string $name = 'Bono 5 cortes', int $sessions = 5, int $price = 30000, int $validityDays = 90, bool $active = true): string
    {
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('voucher_definitions')->insert([
            'id' => $id, 'barbershop_id' => $barbershopId, 'name' => $name, 'sessions_count' => $sessions,
            'price' => $price, 'validity_days' => $validityDays, 'is_active' => $active,
        ]);

        foreach ($services as $service) {
            DB::table('voucher_definition_services')->insert([
                'id' => (string) Str::uuid7(), 'barbershop_id' => $barbershopId, 'voucher_definition_id' => $id, 'service_id' => $service,
            ]);
        }

        return $id;
    }

    /**
     * Insert a voucher a customer bought. An active one has all its sessions left unless told otherwise, and its dates run from
     * 1 March 2030, 8:00 for the days it lasts; the record gets its purchase entry, so the entries add up to the balance.
     */
    private function insertPurchasedVoucher(string $barbershopId, string $membershipId, string $definitionId, string $status = 'active', int $sessions = 5, int $validityDays = 90): string
    {
        $id = (string) Str::uuid7();
        $active = $status === 'active';
        $this->scopeQueriesTo($barbershopId);
        DB::table('purchased_vouchers')->insert([
            'id' => $id, 'barbershop_id' => $barbershopId, 'customer_membership_id' => $membershipId, 'voucher_definition_id' => $definitionId,
            'name' => 'Bono 5 cortes', 'price' => 30000, 'sessions_count' => $sessions, 'validity_days' => $validityDays, 'status' => $status,
            'activated_at' => $active ? '2030-03-01 08:00:00-06' : null,
            'expires_at' => $active ? (new DateTimeImmutable('2030-03-01 08:00:00-06:00'))->modify("+{$validityDays} days")->format('Y-m-d H:i:sP') : null,
            'remaining_sessions' => $active ? $sessions : 0,
        ]);

        if ($active) {
            $this->insertVoucherMovement($barbershopId, $id, 'purchase', $sessions);
        }

        return $id;
    }

    /** Insert an entry in the record of a voucher; a purchase has no reservation, the other kinds need one. */
    private function insertVoucherMovement(string $barbershopId, string $voucherId, string $type, int $delta, ?string $reservationId = null): string
    {
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('voucher_movements')->insert([
            'id' => $id, 'barbershop_id' => $barbershopId, 'purchased_voucher_id' => $voucherId,
            'reservation_id' => $reservationId, 'type' => $type, 'sessions_delta' => $delta,
        ]);

        return $id;
    }
}
