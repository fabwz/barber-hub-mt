<?php

declare(strict_types=1);

namespace Src\Reservations\AssistedClients\Application\UseCases;

use Src\Reservations\AssistedClients\Domain\Contracts\AssistedClientRepository;
use Src\Reservations\AssistedClients\Domain\Entities\AssistedClient;
use Src\Reservations\AssistedClients\Domain\ValueObjects\AssistedClientEmail;
use Src\Reservations\AssistedClients\Domain\ValueObjects\AssistedClientName;
use Src\Shared\Domain\ValueObjects\Phone;

final readonly class RegisterAssistedClient
{
    /** Receive the repository port. */
    public function __construct(private AssistedClientRepository $clients) {}

    /**
     * Register a person for an assisted reservation in the current barbershop (RF-12, CL-06). The name and the phone are required; an empty
     * email means none. It opens no transaction: the reservation that needs the person calls it inside its own, so a reservation that fails
     * leaves nobody behind.
     */
    public function execute(string $name, string $phone, ?string $email): AssistedClient
    {
        $client = AssistedClient::create(
            $this->clients->nextId(),
            new AssistedClientName($name),
            new Phone($phone),
            $email === null || trim($email) === '' ? null : new AssistedClientEmail($email),
        );
        $this->clients->save($client);

        return $client;
    }
}
