<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\Contracts;

use Src\Catalog\Barbershops\Application\DTOs\OwnerAccount;

interface OwnerAccounts
{
    /**
     * The account of the future owner of a barbershop: the one the email already has, untouched, or a new one with a temporary password
     * that is returned this once (RF-01, UP-04, UP-05).
     */
    public function provide(string $name, string $email, string $phone): OwnerAccount;
}
