<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

/**
 * What the customer gives for the method they chose. The card never reaches the server (PCI): the client sends only the
 * token the provider created for it. SINPE Móvil needs the phone, the identification and the name and email of the account.
 */
final readonly class PaymentDetails
{
    private function __construct(
        public ?string $cardToken,
        public ?string $phone,
        public ?string $identification,
        public ?string $identificationType,
        public ?string $payerName,
        public ?string $payerEmail,
    ) {}

    /** A card, identified by the token the provider created in the browser. */
    public static function card(string $token): self
    {
        return new self($token, null, null, null, null, null);
    }

    /** A SINPE Móvil transfer from a phone, for a person with an identification. */
    public static function sinpeMovil(string $phone, string $identification, string $identificationType, string $payerName, string $payerEmail): self
    {
        return new self(null, $phone, $identification, $identificationType, $payerName, $payerEmail);
    }
}
