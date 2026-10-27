<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

/** The name and email of the account that pays; the provider asks for them with SINPE Móvil. */
final readonly class PayerAccount
{
    public function __construct(public string $name, public string $email) {}
}
