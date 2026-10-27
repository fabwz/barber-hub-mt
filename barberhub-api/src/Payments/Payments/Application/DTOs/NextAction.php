<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

use Src\Shared\Domain\ValueObjects\Money;

/** What the customer must do to finish paying: go to a page of the provider or make a transfer. */
final readonly class NextAction
{
    private function __construct(public NextActionType $type, public string $value, public ?Money $amount = null) {}

    /** The customer goes to this URL of the provider. */
    public static function redirectTo(string $url): self
    {
        return new self(NextActionType::Redirect, $url);
    }

    /** The customer transfers exactly this amount by SINPE Móvil to this number. */
    public static function instructions(string $destination, Money $amount): self
    {
        return new self(NextActionType::Instructions, $destination, $amount);
    }
}
