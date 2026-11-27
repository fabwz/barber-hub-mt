<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

use Src\Reservations\Reservations\Application\DTOs\AssistedClientContact;

/** The people the owner registered for assisted reservations, who have no account (RF-12, CL-06). */
interface AssistedCustomers
{
    /**
     * Register a person in the current barbershop and answer their identifier. Called inside the transaction of the reservation, so a rollback
     * takes the person with it.
     */
    public function register(string $name, string $phone, ?string $email): string;

    /** Tell whether the person exists in the current barbershop; one of another barbershop does not (CL-06). */
    public function exists(string $assistedClientId): bool;

    /**
     * The name and phone of each assisted client of the current barbershop, indexed by identifier; the ones it does not have are left out.
     *
     * @param  list<string>  $assistedClientIds
     * @return array<string, AssistedClientContact>
     */
    public function contactsOf(array $assistedClientIds): array;
}
