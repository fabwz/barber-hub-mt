<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

interface CustomerDirectory
{
    /** The identifier of the customer role of a person in the current barbershop, or none if they have not reserved there yet. */
    public function find(string $userId): ?string;

    /**
     * The name of the person behind each customer role of the current barbershop, indexed by role. Never the email or the
     * phone (CO-04).
     *
     * @param  list<string>  $membershipIds
     * @return array<string, string>
     */
    public function namesOf(array $membershipIds): array;
}
