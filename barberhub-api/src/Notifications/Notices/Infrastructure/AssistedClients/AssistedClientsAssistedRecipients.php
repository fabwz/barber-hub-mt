<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\AssistedClients;

use Src\Notifications\Notices\Application\Contracts\AssistedRecipients;
use Src\Notifications\Notices\Application\DTOs\Recipient;
use Src\Reservations\AssistedClients\Application\UseCases\ListAssistedClients;

final readonly class AssistedClientsAssistedRecipients implements AssistedRecipients
{
    /** Receive the use case of the AssistedClients module that reads people of the current barbershop. */
    public function __construct(private ListAssistedClients $clients) {}

    /** Read the assisted client through the AssistedClients module and answer their name and email; null when there is none of either. */
    public function ofAssistedClient(string $assistedClientId): ?Recipient
    {
        $client = $this->clients->execute([$assistedClientId])[0] ?? null;

        return $client === null || $client->email === null ? null : new Recipient($client->name->value, $client->email->value);
    }
}
