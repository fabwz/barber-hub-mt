<?php

declare(strict_types=1);

namespace Tests\Support\Reservations;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customers and reservations written straight into the tables, for the tests that read them. It goes with
 * SeedsBookableBarbershops, whose helpers it uses.
 */
trait SeedsReservations
{
    /** Address of the reservations of the person in a barbershop. */
    private function myReservationsUrl(string $barbershopId): string
    {
        return "/api/v1/barberias/{$barbershopId}/mis-reservas";
    }

    /**
     * Insert an account with the customer role in a barbershop.
     *
     * @return array{string, string} The account and its customer role.
     */
    private function insertCustomer(string $barbershopId, string $name): array
    {
        $userId = $this->insertUser($name);
        $membershipId = (string) Str::uuid7();
        DB::table('memberships')->insert(['id' => $membershipId, 'user_id' => $userId, 'barbershop_id' => $barbershopId, 'role' => 'customer']);

        return [$userId, $membershipId];
    }

    /** Insert a reservation of a barbershop, with the price and the duration it keeps, and return its identifier. */
    private function insertReservation(string $barbershopId, string $collaboratorId, string $serviceId, string $membershipId, string $startsAt, string $status = 'confirmed', int $minutes = 45): string
    {
        $start = new DateTimeImmutable($startsAt);
        $id = (string) Str::uuid7();

        $this->scopeQueriesTo($barbershopId);
        DB::table('reservations')->insert([
            'id' => $id,
            'barbershop_id' => $barbershopId,
            'collaborator_id' => $collaboratorId,
            'service_id' => $serviceId,
            'customer_membership_id' => $membershipId,
            'status' => $status,
            'starts_at' => $start->format('Y-m-d H:i:sP'),
            'ends_at' => $start->modify("+{$minutes} minutes")->format('Y-m-d H:i:sP'),
            'blocked_until' => $start->modify('+'.($minutes + 5).' minutes')->format('Y-m-d H:i:sP'),
            'price' => 7000,
            'duration_minutes' => $minutes,
            'cancellation_deadline_hours' => 24,
        ]);

        return $id;
    }
}
