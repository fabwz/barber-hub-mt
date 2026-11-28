<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use Src\Catalog\Barbershops\Application\Contracts\OwnerAccounts;
use Src\Catalog\Barbershops\Application\DTOs\OwnerAccount;

final class InMemoryOwnerAccounts implements OwnerAccounts
{
    public const string OWNER_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    /** @var list<array{name: string, email: string, phone: string}> */
    private array $requested = [];

    /** @param  ?string  $temporaryPassword  The password a new account would get, or null to behave as an email that already has an account. */
    public function __construct(private readonly ?string $temporaryPassword = 'Temporal#Clave1') {}

    /** Record the request and answer the fixed owner. */
    public function provide(string $name, string $email, string $phone): OwnerAccount
    {
        $this->requested[] = ['name' => $name, 'email' => $email, 'phone' => $phone];

        return new OwnerAccount(self::OWNER_ID, strtolower($email), $this->temporaryPassword);
    }

    /**
     * What was asked for, in order.
     *
     * @return list<array{name: string, email: string, phone: string}>
     */
    public function requested(): array
    {
        return $this->requested;
    }
}
