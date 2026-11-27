<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\AssistedClients;

use Src\Reservations\AssistedClients\Application\UseCases\ListAssistedClients;
use Src\Reservations\AssistedClients\Application\UseCases\RegisterAssistedClient;
use Src\Reservations\Reservations\Application\Contracts\AssistedCustomers;
use Src\Reservations\Reservations\Application\DTOs\AssistedClientContact;

final readonly class AssistedClientsAssistedCustomers implements AssistedCustomers
{
    /** Receive the use cases of the AssistedClients module that register people and read them. */
    public function __construct(
        private RegisterAssistedClient $register,
        private ListAssistedClients $list,
    ) {}

    /** Register the person through the AssistedClients module and keep only the identifier. */
    public function register(string $name, string $phone, ?string $email): string
    {
        return $this->register->execute($name, $phone, $email)->id->value;
    }

    /** Ask the AssistedClients module for the person among those of the current barbershop. */
    public function exists(string $assistedClientId): bool
    {
        return $this->list->execute([$assistedClientId]) !== [];
    }

    /**
     * Read the people in one read and keep only the name and the phone.
     *
     * @param  list<string>  $assistedClientIds
     * @return array<string, AssistedClientContact>
     */
    public function contactsOf(array $assistedClientIds): array
    {
        $contacts = [];

        foreach ($this->list->execute($assistedClientIds) as $client) {
            $contacts[$client->id->value] = new AssistedClientContact($client->name->value, $client->phone->value);
        }

        return $contacts;
    }
}
