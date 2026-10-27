<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

/** What the customer must do to finish paying: go to a page of the provider or follow some instructions. */
final readonly class NextAction
{
    private function __construct(public NextActionType $type, public string $value) {}

    /** The customer goes to this URL of the provider. */
    public static function redirectTo(string $url): self
    {
        return new self(NextActionType::Redirect, $url);
    }

    /** The customer follows these instructions, for example to pay with SINPE Móvil. */
    public static function instructions(string $text): self
    {
        return new self(NextActionType::Instructions, $text);
    }
}
